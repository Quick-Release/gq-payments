<?php
/**
 * The signed wire format shared by both directions.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Header names, canonical string, Content-Digest and strict key decoding.
 *
 * Wire-compatible with tests/fixtures/signing.json and the Node fake Payment
 * Service. Pure PHP: no WordPress calls.
 */
final class Wire {

	public const PREFIX         = 'gq-payments-v1';
	public const KEY_ID         = 'GQ-Payments-Key-Id';
	public const SITE_ID        = 'GQ-Payments-Site-Id';
	public const TIMESTAMP      = 'GQ-Payments-Timestamp';
	public const REQUEST_ID     = 'GQ-Payments-Request-Id';
	public const CONTENT_DIGEST = 'Content-Digest';
	public const SIGNATURE      = 'GQ-Payments-Signature';

	/**
	 * Allowed clock difference, in seconds, either way.
	 */
	public const WINDOW = 300;

	/**
	 * RFC 9530 Content-Digest of the exact body bytes.
	 *
	 * @param string $body Raw body.
	 */
	public static function digest( string $body ): string {
		return 'sha-256=:' . self::encode( hash( 'sha256', $body, true ) ) . ':';
	}

	/**
	 * The string that is signed.
	 *
	 * @param string $method     HTTP method.
	 * @param string $path       Signed path: outbound, the URL path and query as sent;
	 *                           inbound, the REST route plus normalised query (see inboundPath()).
	 * @param string $key_id     Key ID (empty for a Site Key not yet enrolled).
	 * @param string $site_id    Site ID.
	 * @param string $timestamp  Epoch seconds.
	 * @param string $request_id Single-use request ID.
	 * @param string $digest     Content-Digest header value.
	 */
	public static function canonical( string $method, string $path, string $key_id, string $site_id, string $timestamp, string $request_id, string $digest ): string {
		return implode( "\n", [ self::PREFIX, strtoupper( $method ), $path, $key_id, $site_id, $timestamp, $request_id, $digest ] );
	}

	/**
	 * Signed path of an inbound REST request, independent of how it reached WordPress
	 * (/wp-json/… or ?rest_route=…): the route, then the query parameters other than
	 * rest_route sorted by name and RFC 3986 encoded.
	 *
	 * @param string               $route REST route, e.g. /gq-payments/v1/orders/1/snapshot.
	 * @param array<string, mixed> $query Query parameters.
	 */
	public static function inboundPath( string $route, array $query ): string {
		unset( $query['rest_route'] );
		if ( [] === $query ) {
			return $route;
		}
		ksort( $query, SORT_STRING );
		return $route . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Standard (padded) base64, constant-time.
	 *
	 * @param string $raw Raw bytes.
	 */
	public static function encode( string $raw ): string {
		return sodium_bin2base64( $raw, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Decodes standard base64 strictly: the exact length, and a lossless round trip.
	 *
	 * @param string $encoded Base64 text.
	 * @param int    $length  Required decoded length.
	 * @return string|null Raw bytes, or null when malformed.
	 */
	public static function decode( string $encoded, int $length ): ?string {
		try {
			$raw = sodium_base642bin( $encoded, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return null;
		}
		return strlen( $raw ) === $length && self::encode( $raw ) === $encoded ? $raw : null;
	}
}
