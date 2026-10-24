<?php
/**
 * Access to the shared signing vectors in tests/fixtures/signing.json.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Tests\Transport;

/**
 * Loads the vectors the Node fake Payment Service also consumes.
 */
final class Vectors {

	/**
	 * Decoded fixture file.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		static $vectors = null;
		$vectors ??= json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/signing.json' ), true, 512, JSON_THROW_ON_ERROR );
		return $vectors;
	}

	/**
	 * Cases for one direction, keyed by name for data providers.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function cases( string $direction ): iterable {
		foreach ( self::all()['cases'] as $case ) {
			if ( $direction === $case['direction'] ) {
				yield $case['name'] => [ $case ];
			}
		}
	}

	/**
	 * Requests every verifier must reject, keyed by name for data providers.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function rejections(): iterable {
		foreach ( self::all()['rejections'] as $rejection ) {
			yield $rejection['reason'] . ': ' . $rejection['name'] => [ $rejection ];
		}
	}

	/**
	 * One case by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function named( string $name ): array {
		foreach ( self::all()['cases'] as $case ) {
			if ( $name === $case['name'] ) {
				return $case;
			}
		}
		throw new \OutOfBoundsException( $name );
	}

	/**
	 * The fixture's Service Keys as a GQ_PAYMENTS_SERVICE_PUBLIC_KEYS-shaped array.
	 *
	 * @return array<string, array{environment: string, public_key: string}>
	 */
	public static function servicePublicKeys(): array {
		$keys = [];
		foreach ( self::all()['service_keys'] as $id => $key ) {
			$keys[ $id ] = [
				'environment' => $key['environment'],
				'public_key'  => $key['public_key'],
			];
		}
		return $keys;
	}
}
