<?php
/**
 * Verifies the Payment Service's signed requests to the site.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Fails closed: any malformed, unknown, stale, tampered, foreign or replayed
 * request yields null, without saying why. Pure PHP apart from the replay store.
 */
final class Verifier {

	/**
	 * Builds a verifier for this site.
	 *
	 * @param ServiceKeys $keys        Trusted Service Keys.
	 * @param ReplayStore $replay      Seen request IDs.
	 * @param string      $siteId      This site's Site ID ('' rejects everything).
	 * @param string|null $environment Enrolled environment; when known, only its keys are accepted.
	 */
	public function __construct(
		private ServiceKeys $keys,
		private ReplayStore $replay,
		private string $siteId,
		private ?string $environment = null
	) {}

	/**
	 * Verifies one request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $path    Signed path (Wire::inboundPath() for REST requests).
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Received headers; names are case-insensitive.
	 * @param int                   $now     Epoch seconds.
	 * @return ServiceKey|null The key that signed a genuine request, or null.
	 */
	public function verify( string $method, string $path, string $body, array $headers, int $now ): ?ServiceKey {
		$headers   = array_change_key_case( $headers, CASE_LOWER );
		$header    = static fn( string $name ): string => is_string( $headers[ strtolower( $name ) ] ?? null ) ? $headers[ strtolower( $name ) ] : '';
		$keyId     = $header( Wire::KEY_ID );
		$siteId    = $header( Wire::SITE_ID );
		$timestamp = $header( Wire::TIMESTAMP );
		$requestId = $header( Wire::REQUEST_ID );
		$digest    = $header( Wire::CONTENT_DIGEST );

		if ( '' === $this->siteId || ! hash_equals( $this->siteId, $siteId )
			|| 1 !== preg_match( '/^\d{10}$/', $timestamp ) || abs( $now - (int) $timestamp ) > Wire::WINDOW
			|| 1 !== preg_match( '/^[a-zA-Z0-9-]{16,128}$/', $requestId )
			|| ! hash_equals( Wire::digest( $body ), $digest ) ) {
			return null;
		}

		$key = $this->keys->find( $keyId );
		if ( null === $key || ( null !== $this->environment && $key->environment !== $this->environment ) ) {
			return null;
		}

		$signature = Wire::decode( $header( Wire::SIGNATURE ), SODIUM_CRYPTO_SIGN_BYTES );
		$canonical = Wire::canonical( $method, $path, $keyId, $siteId, $timestamp, $requestId, $digest );
		try {
			if ( null === $signature || ! sodium_crypto_sign_verify_detached( $signature, $canonical, $key->publicKey ) ) {
				return null;
			}
		} catch ( \SodiumException ) {
			return null;
		}

		// Only authenticated requests reach the store, so forgeries cannot fill it.
		return $this->replay->claim( $key->id, $requestId, $now ) ? $key : null;
	}
}
