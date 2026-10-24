<?php
/**
 * Entry points of the signed transport for the rest of the plugin.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

use GetQuick\Payments\Configuration;
use WP_Error;
use WP_REST_Request;

/**
 * Inbound: use Transport::authorize as a REST route's permission_callback.
 * Outbound: Transport::client( new Signer( $siteKey, $keyId, $siteId ) ).
 */
final class Transport {

	/**
	 * This site's Site ID. Written by the Connection module; kept after disconnect.
	 */
	public const SITE_ID_OPTION = 'gq_payments_site_id';

	/**
	 * Environment fixed by the Site Enrollment ('sandbox'|'production'); absent until known.
	 */
	public const ENVIRONMENT_OPTION = 'gq_payments_environment';

	/**
	 * REST permission callback: true for a genuine, fresh, first-seen request signed by a
	 * trusted Service Key for this site; otherwise a generic 401 that says nothing about why.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		return null !== self::verify( $request ) ? true : new WP_Error(
			'gq_payments_unauthorized',
			__( 'Request not authorized.', 'gq-payments' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * Verifies a request and returns the Service Key that signed it, or null.
	 * Each request ID is accepted once: call this (or authorize()) once per request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function verify( WP_REST_Request $request ): ?ServiceKey {
		$environment = get_option( self::ENVIRONMENT_OPTION );
		$headers     = [];
		foreach ( [ Wire::KEY_ID, Wire::SITE_ID, Wire::TIMESTAMP, Wire::REQUEST_ID, Wire::CONTENT_DIGEST, Wire::SIGNATURE ] as $name ) {
			$headers[ $name ] = (string) $request->get_header( $name );
		}

		DatabaseReplayStore::install();
		$verifier = new Verifier(
			ServiceKeys::configured(),
			new DatabaseReplayStore(),
			(string) get_option( self::SITE_ID_OPTION, '' ),
			in_array( $environment, ServiceKeys::ENVIRONMENTS, true ) ? $environment : null
		);

		return $verifier->verify(
			$request->get_method(),
			Wire::inboundPath( $request->get_route(), $request->get_query_params() ),
			$request->get_body(),
			$headers,
			time()
		);
	}

	/**
	 * Client for the configured Payment Service URL.
	 *
	 * @param Signer $signer Signs with the Site Key.
	 */
	public static function client( Signer $signer ): ServiceClient {
		return new ServiceClient( Configuration::serviceUrl(), $signer );
	}
}
