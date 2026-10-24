<?php
/**
 * Inbound verification (Payment Service → site) against the shared vectors.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests\Transport;

use GetQuick\Payments\Transport\MemoryReplayStore;
use GetQuick\Payments\Transport\ServiceKeys;
use GetQuick\Payments\Transport\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase {

	private const SITE_ID = '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function inbound(): iterable {
		return Vectors::cases( 'inbound' );
	}

	/**
	 * A verifier trusting the fixture's Service Keys.
	 *
	 * @param string|null $environment Enrolled environment, when known.
	 */
	private static function verifier( ?MemoryReplayStore $replay = null, ?string $environment = null, string $siteId = self::SITE_ID ): Verifier {
		return new Verifier( ServiceKeys::fromArray( Vectors::servicePublicKeys() ), $replay ?? new MemoryReplayStore(), $siteId, $environment );
	}

	/**
	 * Headers as they arrive, with Content-Digest and signature.
	 *
	 * @param array<string, mixed> $vector Shared vector.
	 * @return array<string, string>
	 */
	private static function headers( array $vector ): array {
		return $vector['headers'] + [
			'Content-Digest'        => $vector['content_digest'],
			'GQ-Payments-Signature' => $vector['signature'],
		];
	}

	/**
	 * @param array<string, mixed> $vector Shared vector.
	 */
	private static function now( array $vector ): int {
		return (int) $vector['headers']['GQ-Payments-Timestamp'];
	}

	/**
	 * @param array<string, mixed> $vector Shared vector.
	 */
	#[DataProvider( 'inbound' )]
	public function test_genuine_service_requests_are_accepted_with_the_signing_key( array $vector ): void {
		$key = self::verifier()->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) );

		$this->assertNotNull( $key );
		$this->assertSame( $vector['signer'], $key->id );
		$this->assertSame( Vectors::all()['service_keys'][ $vector['signer'] ]['environment'], $key->environment );
	}

	public function test_header_names_are_case_insensitive(): void {
		$vector  = Vectors::all()['cases'][2];
		$headers = array_change_key_case( self::headers( $vector ), CASE_LOWER );

		$this->assertNotNull( self::verifier()->verify( $vector['method'], $vector['path'], $vector['body'], $headers, self::now( $vector ) ) );
	}

	/**
	 * Single-field mutations of the current-key snapshot vector and the next-key state vector.
	 *
	 * @return iterable<string, array{int, callable(array<string, mixed>): array<string, mixed>}>
	 */
	public static function tampering(): iterable {
		$header = static fn( string $name, string $value ) => static function ( array $r ) use ( $name, $value ): array {
			$r['headers'][ $name ] = $value;
			return $r;
		};
		foreach ( [ 2, 3 ] as $index ) {
			yield "#$index method" => [ $index, static fn( array $r ): array => [ 'method' => 'PUT' ] + $r ];
			yield "#$index path" => [ $index, static fn( array $r ): array => [ 'path' => '/gq-payments/v1/orders/124/snapshot' ] + $r ];
			yield "#$index query" => [ $index, static fn( array $r ): array => [ 'path' => $r['path'] . '?key=other' ] + $r ];
			yield "#$index body with its own matching digest" => [
				$index,
				static fn( array $r ): array => [
					'body'    => $r['body'] . ' ',
					'headers' => [ 'Content-Digest' => 'sha-256=:' . base64_encode( hash( 'sha256', $r['body'] . ' ', true ) ) . ':' ] + $r['headers'],
				] + $r,
			];
			yield "#$index body without digest change (bad digest)" => [ $index, static fn( array $r ): array => [ 'body' => '{"state":"paid"}' ] + $r ];
			yield "#$index site ID" => [ $index, $header( 'GQ-Payments-Site-Id', '00000000-0000-4000-8000-000000000000' ) ];
			yield "#$index timestamp" => [ $index, $header( 'GQ-Payments-Timestamp', (string) ( (int) Vectors::all()['cases'][ $index ]['headers']['GQ-Payments-Timestamp'] + 1 ) ) ];
			yield "#$index request ID" => [ $index, $header( 'GQ-Payments-Request-Id', 'c1c2d3e4-0000-4000-8000-0000000000ff' ) ];
			yield "#$index other trusted key ID" => [ $index, $header( 'GQ-Payments-Key-Id', 2 === $index ? 'test-sandbox-next' : 'test-sandbox-current' ) ];
			yield "#$index unknown key ID" => [ $index, $header( 'GQ-Payments-Key-Id', 'nobody' ) ];
			yield "#$index signature from another case" => [ $index, $header( 'GQ-Payments-Signature', Vectors::all()['cases'][ 2 === $index ? 3 : 2 ]['signature'] ) ];
			yield "#$index signature not strict base64" => [ $index, $header( 'GQ-Payments-Signature', rtrim( Vectors::all()['cases'][ $index ]['signature'], '=' ) ) ];
			yield "#$index missing signature" => [
				$index,
				static function ( array $r ): array {
					unset( $r['headers']['GQ-Payments-Signature'] );
					return $r;
				},
			];
			yield "#$index malformed timestamp" => [ $index, $header( 'GQ-Payments-Timestamp', '179000000' ) ];
			yield "#$index malformed request ID" => [ $index, $header( 'GQ-Payments-Request-Id', 'short' ) ];
		}
	}

	/**
	 * @param int                                                $index  Vector index.
	 * @param callable(array<string, mixed>): array<string, mixed> $mutate Mutation.
	 */
	#[DataProvider( 'tampering' )]
	public function test_tampered_requests_are_rejected( int $index, callable $mutate ): void {
		$vector  = Vectors::all()['cases'][ $index ];
		$request = $mutate(
			[
				'method'  => $vector['method'],
				'path'    => $vector['path'],
				'body'    => $vector['body'],
				'headers' => self::headers( $vector ),
			]
		);

		$this->assertNull( self::verifier()->verify( $request['method'], $request['path'], $request['body'], $request['headers'], self::now( $vector ) ) );
	}

	public function test_a_request_signed_by_an_untrusted_key_is_rejected(): void {
		$vector  = Vectors::all()['cases'][2];
		$forged  = sodium_crypto_sign_detached( $vector['canonical'], base64_decode( Vectors::all()['stranger_key']['secret_key'] ) );
		$headers = [ 'GQ-Payments-Signature' => base64_encode( $forged ) ] + self::headers( $vector );

		$this->assertNull( self::verifier()->verify( $vector['method'], $vector['path'], $vector['body'], $headers, self::now( $vector ) ) );
	}

	/**
	 * @return iterable<string, array{int, bool}>
	 */
	public static function clock(): iterable {
		yield 'service clock 300 s ahead' => [ -300, true ];
		yield 'service clock 300 s behind' => [ 300, true ];
		yield 'service clock 301 s ahead' => [ -301, false ];
		yield 'stale by 301 s' => [ 301, false ];
		yield 'stale by an hour' => [ 3600, false ];
	}

	#[DataProvider( 'clock' )]
	public function test_timestamps_must_be_within_five_minutes( int $skew, bool $accepted ): void {
		$vector = Vectors::all()['cases'][2];

		$key = self::verifier()->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) + $skew );

		$this->assertSame( $accepted, null !== $key );
	}

	public function test_a_replayed_request_id_is_rejected(): void {
		$vector   = Vectors::all()['cases'][3];
		$verifier = self::verifier();

		$first  = $verifier->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) );
		$replay = $verifier->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) + 60 );

		$this->assertNotNull( $first );
		$this->assertNull( $replay );
	}

	public function test_seen_request_ids_are_remembered_beyond_the_window(): void {
		$vector = Vectors::all()['cases'][3];
		$replay = new MemoryReplayStore();
		self::verifier( $replay )->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) - 300 );

		// A second verifier sharing the store, at the far edge of the window.
		$key = self::verifier( $replay )->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) + 300 );

		$this->assertNull( $key );
	}

	public function test_a_forged_request_does_not_consume_its_request_id(): void {
		$vector = Vectors::all()['cases'][3];
		$replay = new MemoryReplayStore();
		$forged = [ 'GQ-Payments-Signature' => Vectors::all()['cases'][2]['signature'] ] + self::headers( $vector );
		self::verifier( $replay )->verify( $vector['method'], $vector['path'], $vector['body'], $forged, self::now( $vector ) );

		$this->assertNotNull( self::verifier( $replay )->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) ) );
	}

	public function test_request_ids_are_single_use_per_key(): void {
		$replay = new MemoryReplayStore();

		$this->assertTrue( $replay->claim( 'test-sandbox-current', 'c1c2d3e4-0000-4000-8000-000000000003', 1790000000 ) );
		$this->assertTrue( $replay->claim( 'test-sandbox-next', 'c1c2d3e4-0000-4000-8000-000000000003', 1790000000 ) );
		$this->assertFalse( $replay->claim( 'test-sandbox-current', 'c1c2d3e4-0000-4000-8000-000000000003', 1790000500 ) );
	}

	public function test_requests_for_another_site_are_rejected(): void {
		$vector = Vectors::all()['cases'][2];

		$key = self::verifier( siteId: 'another-site' )->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) );

		$this->assertNull( $key );
	}

	public function test_a_site_without_a_site_id_accepts_nothing(): void {
		$vector = Vectors::all()['cases'][2];

		$this->assertNull( self::verifier( siteId: '' )->verify( $vector['method'], $vector['path'], $vector['body'], self::headers( $vector ), self::now( $vector ) ) );
	}

	public function test_once_enrolled_only_keys_of_that_environment_are_accepted(): void {
		$sandbox    = Vectors::all()['cases'][3];
		$production = Vectors::all()['cases'][4];

		$this->assertNull( self::verifier( environment: 'production' )->verify( $sandbox['method'], $sandbox['path'], $sandbox['body'], self::headers( $sandbox ), self::now( $sandbox ) ) );
		$this->assertNotNull( self::verifier( environment: 'production' )->verify( $production['method'], $production['path'], $production['body'], self::headers( $production ), self::now( $production ) ) );
		$this->assertNotNull( self::verifier( environment: 'sandbox' )->verify( $sandbox['method'], $sandbox['path'], $sandbox['body'], self::headers( $sandbox ), self::now( $sandbox ) ) );
	}
}
