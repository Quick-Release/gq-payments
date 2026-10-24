<?php
/**
 * Signs the site's requests with its Site Key.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

use InvalidArgumentException;

/**
 * Produces the signed headers for one outbound request. Pure PHP.
 */
final class Signer {

	/**
	 * 64-byte sodium secret key (seed ‖ public key).
	 *
	 * @var string
	 */
	private string $secretKey;

	/**
	 * Builds a signer.
	 *
	 * @param string $siteKey Site Key, base64: a 64-byte sodium secret key or its 32-byte seed.
	 * @param string $keyId   Key ID the Payment Service knows the Site Key by ('' before enrollment).
	 * @param string $siteId  Site ID.
	 * @throws InvalidArgumentException When the Site Key is malformed. The message never contains the key.
	 */
	public function __construct( string $siteKey, private string $keyId, private string $siteId ) {
		$secret = Wire::decode( $siteKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES );
		if ( null === $secret ) {
			$seed = Wire::decode( $siteKey, SODIUM_CRYPTO_SIGN_SEEDBYTES );
			if ( null === $seed ) {
				throw new InvalidArgumentException( 'The Site Key is not a base64 Ed25519 secret key or seed.' );
			}
			$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $seed ) );
		}
		$this->secretKey = $secret;
	}

	/**
	 * Base64 public half of the Site Key.
	 */
	public function publicKey(): string {
		return Wire::encode( sodium_crypto_sign_publickey_from_secretkey( $this->secretKey ) );
	}

	/**
	 * Signed headers for a request.
	 *
	 * @param string      $method    HTTP method.
	 * @param string      $path      URL path plus query, exactly as sent.
	 * @param string      $body      Exact body bytes.
	 * @param int|null    $timestamp Epoch seconds; defaults to now.
	 * @param string|null $requestId Single-use request ID; defaults to a fresh UUIDv4.
	 * @return array<string, string>
	 */
	public function headers( string $method, string $path, string $body, ?int $timestamp = null, ?string $requestId = null ): array {
		$timestamp = (string) ( $timestamp ?? time() );
		$requestId = $requestId ?? self::uuid();
		$digest    = Wire::digest( $body );
		$canonical = Wire::canonical( $method, $path, $this->keyId, $this->siteId, $timestamp, $requestId, $digest );

		return [
			Wire::KEY_ID         => $this->keyId,
			Wire::SITE_ID        => $this->siteId,
			Wire::TIMESTAMP      => $timestamp,
			Wire::REQUEST_ID     => $requestId,
			Wire::CONTENT_DIGEST => $digest,
			Wire::SIGNATURE      => Wire::encode( sodium_crypto_sign_detached( $canonical, $this->secretKey ) ),
		];
	}

	/**
	 * Random UUIDv4.
	 */
	private static function uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
