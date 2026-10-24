<?php
/**
 * Signed calls from the site to the Payment Service.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Sends Site-Key-signed requests through wp_safe_remote_request(), over HTTPS
 * only, without following redirects and with a bounded response size.
 */
final class ServiceClient {

	public const MAX_RESPONSE_BYTES = 1048576;
	public const TIMEOUT            = 10;

	/**
	 * Builds a client.
	 *
	 * @param string $baseUrl Payment Service base URL (https://…, optionally with a path prefix).
	 * @param Signer $signer  Signs with the Site Key.
	 */
	public function __construct( private string $baseUrl, private Signer $signer ) {}

	/**
	 * Signed GET.
	 *
	 * @param string $path Path (and query) below the base URL, starting with '/'.
	 */
	public function get( string $path ): ServiceResponse {
		return $this->request( 'GET', $path );
	}

	/**
	 * Signed POST of a JSON payload.
	 *
	 * @param string       $path    Path (and query) below the base URL, starting with '/'.
	 * @param array<mixed> $payload JSON payload.
	 */
	public function post( string $path, array $payload ): ServiceResponse {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS );
		return false === $body ? ServiceResponse::unreachable() : $this->request( 'POST', $path, $body );
	}

	/**
	 * Signed request with an exact body.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path (and query) below the base URL, starting with '/'.
	 * @param string $body   Exact body bytes ('' for none).
	 */
	public function request( string $method, string $path, string $body = '' ): ServiceResponse {
		$url = $this->baseUrl . $path;
		if ( ! str_starts_with( $this->baseUrl, 'https://' ) || ! str_starts_with( $path, '/' ) ) {
			return ServiceResponse::unreachable();
		}
		$parts      = wp_parse_url( $url );
		$signedPath = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$headers    = $this->signer->headers( $method, $signedPath, $body );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$response = wp_safe_remote_request(
			$url,
			[
				'method'              => strtoupper( $method ),
				'headers'             => $headers,
				'body'                => '' === $body ? null : $body,
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
				'sslverify'           => true,
			]
		);
		if ( is_wp_error( $response ) ) {
			return ServiceResponse::unreachable();
		}

		$responseBody = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $responseBody ) > self::MAX_RESPONSE_BYTES ) {
			return ServiceResponse::unreachable();
		}
		return new ServiceResponse( (int) wp_remote_retrieve_response_code( $response ), $responseBody );
	}
}
