<?php
/**
 * The set of Service Keys the site trusts.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Service Keys shipped with the release, a current and a next key per environment
 * (ADR 0002). Only the GQ_PAYMENTS_SERVICE_PUBLIC_KEYS constant can replace them;
 * there is deliberately no environment-variable or option override.
 */
final class ServiceKeys {

	public const ENVIRONMENTS = [ 'sandbox', 'production' ];

	/**
	 * Release defaults, in the GQ_PAYMENTS_SERVICE_PUBLIC_KEYS shape.
	 *
	 * PLACEHOLDERS: the Payment Service's real keys do not exist yet. An empty
	 * public key is invalid, so these entries verify nothing until a release
	 * ships the real current and next keys.
	 */
	public const SHIPPED = [
		'placeholder-sandbox-current'    => [
			'environment' => 'sandbox',
			'public_key'  => '',
		],
		'placeholder-sandbox-next'       => [
			'environment' => 'sandbox',
			'public_key'  => '',
		],
		'placeholder-production-current' => [
			'environment' => 'production',
			'public_key'  => '',
		],
		'placeholder-production-next'    => [
			'environment' => 'production',
			'public_key'  => '',
		],
	];

	/**
	 * Valid keys by key ID.
	 *
	 * @param array<string, ServiceKey> $keys Keys.
	 */
	private function __construct( private array $keys ) {}

	/**
	 * The keys in effect: the constant when defined as an array, otherwise the release defaults.
	 */
	public static function configured(): self {
		if ( defined( 'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS' ) && is_array( constant( 'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS' ) ) ) {
			return self::fromArray( constant( 'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS' ) );
		}
		return self::shipped();
	}

	/**
	 * The release defaults.
	 */
	public static function shipped(): self {
		return self::fromArray( self::SHIPPED );
	}

	/**
	 * Parses key ID => ['environment' => 'sandbox'|'production', 'public_key' => base64 32 bytes].
	 * Malformed entries are dropped, so they can never verify anything.
	 *
	 * @param array<mixed> $entries Entries.
	 */
	public static function fromArray( array $entries ): self {
		$keys = [];
		foreach ( $entries as $id => $entry ) {
			if ( ! is_string( $id ) || ! self::validId( $id ) || ! is_array( $entry ) ) {
				continue;
			}
			$environment = $entry['environment'] ?? null;
			$public      = is_string( $entry['public_key'] ?? null ) ? Wire::decode( $entry['public_key'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) : null;
			if ( null === $public || ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
				continue;
			}
			$keys[ $id ] = new ServiceKey( $id, $environment, $public );
		}
		return new self( $keys );
	}

	/**
	 * Whether a string can be a key ID on the wire.
	 *
	 * @param string $id Candidate.
	 */
	public static function validId( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $id );
	}

	/**
	 * The trusted key with this ID.
	 *
	 * @param string $id Key ID.
	 */
	public function find( string $id ): ?ServiceKey {
		return $this->keys[ $id ] ?? null;
	}
}
