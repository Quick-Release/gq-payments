<?php
/**
 * One Site Key and the key ID the Payment Service knows it by.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Connection;

use GetQuick\Payments\Transport\Signer;
use GetQuick\Payments\Transport\Wire;
use InvalidArgumentException;

/**
 * Holds the private half only to sign; it never leaves this object except to
 * SiteKeys' option storage (and never when GQ_PAYMENTS_SITE_KEY* constants manage it).
 */
final class SiteKey {

	/**
	 * Base64 public key.
	 *
	 * @var string
	 */
	public readonly string $publicKey;

	/**
	 * Builds a key.
	 *
	 * @param string $secret Base64 Ed25519 secret key or seed.
	 * @param string $keyId  Key ID assigned by the Payment Service ('' until the Connection Request is sent).
	 * @throws InvalidArgumentException When the secret is malformed. The message never contains it.
	 */
	public function __construct( private string $secret, public readonly string $keyId = '' ) {
		$this->publicKey = ( new Signer( $secret, '', '' ) )->publicKey();
	}

	/**
	 * A fresh random key.
	 */
	public static function generate(): self {
		return new self( Wire::encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
	}

	/**
	 * The same key under the key ID the Payment Service assigned.
	 *
	 * @param string $keyId Key ID.
	 */
	public function withKeyId( string $keyId ): self {
		return new self( $this->secret, $keyId );
	}

	/**
	 * Short fingerprint an Operator compares before approving: SHA256:xxxx:xxxx:xxxx:xxxx.
	 */
	public function fingerprint(): string {
		$hash = hash( 'sha256', (string) Wire::decode( $this->publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) );
		return 'SHA256:' . implode( ':', str_split( substr( $hash, 0, 16 ), 4 ) );
	}

	/**
	 * Signs this site's requests with this key.
	 *
	 * @param string $siteId Site ID.
	 */
	public function signer( string $siteId ): Signer {
		return new Signer( $this->secret, $this->keyId, $siteId );
	}

	/**
	 * Storage form; the secret is omitted when it comes from a GQ_PAYMENTS_SITE_KEY* constant,
	 * and the public key and its fingerprint then identify which constant backs the entry.
	 *
	 * @param bool $withSecret Whether to include the private key.
	 * @return array{key_id: string, public_key: string, fingerprint: string, secret?: string}
	 */
	public function toStored( bool $withSecret ): array {
		$stored = [
			'key_id'      => $this->keyId,
			'public_key'  => $this->publicKey,
			'fingerprint' => $this->fingerprint(),
		];
		if ( $withSecret ) {
			$stored['secret'] = $this->secret;
		}
		return $stored;
	}
}
