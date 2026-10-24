<?php
/**
 * Outbound signing (site → Payment Service) against the shared vectors.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests\Transport;

use GetQuick\Payments\Transport\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function outbound(): iterable {
		return Vectors::cases( 'outbound' );
	}

	/**
	 * @param array<string, mixed> $vector Shared vector.
	 */
	#[DataProvider( 'outbound' )]
	public function test_site_key_signs_the_exact_wire_format( array $vector ): void {
		$signer = new Signer(
			Vectors::all()['site_key']['secret_key'],
			$vector['headers']['GQ-Payments-Key-Id'],
			$vector['headers']['GQ-Payments-Site-Id']
		);

		$headers = $signer->headers(
			$vector['method'],
			$vector['path'],
			$vector['body'],
			(int) $vector['headers']['GQ-Payments-Timestamp'],
			$vector['headers']['GQ-Payments-Request-Id']
		);

		$this->assertSame(
			$vector['headers'] + [
				'Content-Digest'        => $vector['content_digest'],
				'GQ-Payments-Signature' => $vector['signature'],
			],
			$headers
		);
	}

	public function test_a_32_byte_seed_signs_like_its_64_byte_secret(): void {
		$vector = Vectors::all()['cases'][1];
		$seed   = base64_encode( substr( base64_decode( Vectors::all()['site_key']['secret_key'] ), 0, 32 ) );
		$signer = new Signer( $seed, $vector['headers']['GQ-Payments-Key-Id'], $vector['headers']['GQ-Payments-Site-Id'] );

		$headers = $signer->headers( $vector['method'], $vector['path'], $vector['body'], (int) $vector['headers']['GQ-Payments-Timestamp'], $vector['headers']['GQ-Payments-Request-Id'] );

		$this->assertSame( $vector['signature'], $headers['GQ-Payments-Signature'] );
	}

	public function test_fresh_requests_get_the_current_time_and_a_unique_uuid_request_id(): void {
		$signer = new Signer( Vectors::all()['site_key']['secret_key'], 'site-key-0001', 'site-1' );

		$first  = $signer->headers( 'GET', '/v1/site/status', '' );
		$second = $signer->headers( 'GET', '/v1/site/status', '' );

		$this->assertEqualsWithDelta( time(), (int) $first['GQ-Payments-Timestamp'], 2 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['GQ-Payments-Request-Id'] );
		$this->assertNotSame( $first['GQ-Payments-Request-Id'], $second['GQ-Payments-Request-Id'] );
	}

	public function test_a_malformed_key_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Signer( 'not-a-key', 'k', 's' );
	}
}
