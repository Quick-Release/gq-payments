<?php
/**
 * The Service Key set shipped with the plugin.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests\Transport;

use GetQuick\Payments\Transport\MemoryReplayStore;
use GetQuick\Payments\Transport\ServiceKeys;
use GetQuick\Payments\Transport\Verifier;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ServiceKeysTest extends TestCase {

	public function test_the_release_ships_a_current_and_a_next_key_per_environment(): void {
		$environments = [];
		foreach ( ServiceKeys::SHIPPED as $id => $key ) {
			$environments[ $key['environment'] ][] = $id;
		}

		ksort( $environments );
		$this->assertSame( [ 'production', 'sandbox' ], array_keys( $environments ) );
		$this->assertCount( 2, $environments['sandbox'] );
		$this->assertCount( 2, $environments['production'] );
	}

	public function test_shipped_placeholders_verify_nothing(): void {
		$vector  = Vectors::all()['cases'][2];
		$headers = $vector['headers'] + [
			'Content-Digest'        => $vector['content_digest'],
			'GQ-Payments-Signature' => $vector['signature'],
		];

		foreach ( array_keys( ServiceKeys::SHIPPED ) as $id ) {
			$headers['GQ-Payments-Key-Id'] = $id;
			$verifier                      = new Verifier( ServiceKeys::shipped(), new MemoryReplayStore(), $vector['headers']['GQ-Payments-Site-Id'] );
			$this->assertNull( $verifier->verify( $vector['method'], $vector['path'], $vector['body'], $headers, (int) $vector['headers']['GQ-Payments-Timestamp'] ), $id );
		}
	}

	public function test_malformed_entries_are_ignored(): void {
		$keys = ServiceKeys::fromArray(
			[
				'good'          => [
					'environment' => 'sandbox',
					'public_key'  => Vectors::all()['service_keys']['test-sandbox-current']['public_key'],
				],
				'short key'     => [
					'environment' => 'sandbox',
					'public_key'  => 'AAAA',
				],
				'unknown env'   => [
					'environment' => 'staging',
					'public_key'  => Vectors::all()['service_keys']['test-sandbox-next']['public_key'],
				],
				'not an array'  => 'x',
				"new\nline"     => [
					'environment' => 'sandbox',
					'public_key'  => Vectors::all()['service_keys']['test-sandbox-next']['public_key'],
				],
			]
		);

		$this->assertNotNull( $keys->find( 'good' ) );
		$this->assertNull( $keys->find( 'short key' ) );
		$this->assertNull( $keys->find( 'unknown env' ) );
		$this->assertNull( $keys->find( 'not an array' ) );
		$this->assertNull( $keys->find( "new\nline" ) );
	}

	public function test_without_the_constant_the_shipped_keys_apply(): void {
		$this->assertEquals( ServiceKeys::shipped(), ServiceKeys::configured() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_constant_replaces_the_shipped_keys(): void {
		define( 'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS', Vectors::servicePublicKeys() );

		$keys = ServiceKeys::configured();

		$this->assertNotNull( $keys->find( 'test-sandbox-current' ) );
		foreach ( array_keys( ServiceKeys::SHIPPED ) as $id ) {
			$this->assertNull( $keys->find( $id ) );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_environment_variable_cannot_override_the_keys(): void {
		putenv( 'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS=' . json_encode( Vectors::servicePublicKeys() ) );

		$this->assertNull( ServiceKeys::configured()->find( 'test-sandbox-current' ) );
	}
}
