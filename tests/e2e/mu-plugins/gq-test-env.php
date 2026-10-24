<?php
/**
 * Plugin Name: GQ Payments test environment
 * Description: Test-only wiring. Points GQ Payments at the fake Payment Service (tests/e2e/fake-service) with the test keys from tests/fixtures/signing.json, registers gq-test/v1 fixture routes, and records outbound HTTP made while a plugin is being uninstalled in option gq_test_uninstall_http.
 *
 * Options that steer it:
 * - gq_test_site_key_constant = "off": leave GQ_PAYMENTS_SITE_KEY undefined (Site Key custody falls back to the option).
 * - gq_test_site_key: base64 secret for GQ_PAYMENTS_SITE_KEY instead of the fixture Site Key.
 * - gq_test_site_key_next: base64 secret for GQ_PAYMENTS_SITE_KEY_NEXT (undefined otherwise).
 * - gq_test_key_id: key ID the relay route signs with (default site-key-0001).
 *
 * @package GetQuick\Payments\Tests
 */

use GetQuick\Payments\Configuration;
use GetQuick\Payments\Transport\Signer;
use GetQuick\Payments\Transport\Transport;

const GQ_TEST_FAKE_SERVICE_HOST = 'host.docker.internal';
// Overridable from wp-env config (e.g. .wp-env.override.json) to run parallel environments.
if ( ! defined( 'GQ_TEST_FAKE_SERVICE_PORT' ) ) {
	define( 'GQ_TEST_FAKE_SERVICE_PORT', 9443 );
}

$gq_test_vectors = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/gq-payments/tests/fixtures/signing.json' ), true );

define( 'GQ_PAYMENTS_SERVICE_URL', 'https://' . GQ_TEST_FAKE_SERVICE_HOST . ':' . GQ_TEST_FAKE_SERVICE_PORT );
define(
	'GQ_PAYMENTS_SERVICE_PUBLIC_KEYS',
	array_map(
		static fn( array $key ): array => [
			'environment' => $key['environment'],
			'public_key'  => $key['public_key'],
		],
		$gq_test_vectors['service_keys']
	)
);
if ( 'off' !== get_option( 'gq_test_site_key_constant' ) ) {
	define( 'GQ_PAYMENTS_SITE_KEY', (string) get_option( 'gq_test_site_key', $gq_test_vectors['site_key']['secret_key'] ) );
	if ( '' !== (string) get_option( 'gq_test_site_key_next', '' ) ) {
		define( 'GQ_PAYMENTS_SITE_KEY_NEXT', (string) get_option( 'gq_test_site_key_next' ) );
	}
}

// The fake runs on the Docker host (a private IP) on a non-standard port with a
// test-only self-signed certificate. Relax exactly those checks, for that host only.
$gq_test_is_fake = static fn( string $url ): bool => GQ_TEST_FAKE_SERVICE_HOST === wp_parse_url( $url, PHP_URL_HOST ) && GQ_TEST_FAKE_SERVICE_PORT === wp_parse_url( $url, PHP_URL_PORT );
add_filter(
	'http_request_host_is_external',
	static fn( $external, $host, $url ) => $external || $gq_test_is_fake( $url ),
	10,
	3
);
add_filter(
	'http_allowed_safe_ports',
	static fn( $ports, $host ) => GQ_TEST_FAKE_SERVICE_HOST === $host ? array_merge( $ports, [ GQ_TEST_FAKE_SERVICE_PORT ] ) : $ports,
	10,
	2
);
add_filter(
	'http_request_args',
	static function ( $args, $url ) use ( $gq_test_is_fake ) {
		if ( $gq_test_is_fake( $url ) ) {
			$args['sslverify'] = false;
		}
		return $args;
	},
	10,
	2
);

// Fixture routes standing in for the payment routes later slices add. Both are
// guarded by the real Transport::authorize permission callback.
add_action(
	'rest_api_init',
	static function (): void {
		if ( ! class_exists( Transport::class ) ) {
			return;
		}
		// Proves a request was authorized.
		register_rest_route(
			'gq-test/v1',
			'/ping',
			[
				'methods'             => [ 'GET', 'POST' ],
				'permission_callback' => [ Transport::class, 'authorize' ],
				'callback'            => static fn() => [ 'verified' => true ],
			]
		);
		// Makes a signed outbound call to the Payment Service and reports the reply.
		// Body: { method: 'GET'|'POST', path: '/v1/…', payload?: object }.
		register_rest_route(
			'gq-test/v1',
			'/relay',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Transport::class, 'authorize' ],
				'callback'            => static function ( WP_REST_Request $request ) {
					$call   = (array) $request->get_json_params();
					$signer = new Signer( Configuration::siteKey(), (string) get_option( 'gq_test_key_id', 'site-key-0001' ), (string) get_option( Transport::SITE_ID_OPTION ) );
					$client = Transport::client( $signer );
					$reply  = 'POST' === ( $call['method'] ?? 'GET' )
						? $client->post( (string) $call['path'], (array) ( $call['payload'] ?? [] ) )
						: $client->get( (string) $call['path'] );
					return [
						'status' => $reply->status,
						'json'   => $reply->json(),
					];
				},
			]
		);
	}
);

// A fresh WooCommerce install sets _wc_activation_redirect for 30 s, and the first
// admin page loaded in that time redirects (302) to the onboarding wizard. Tests that
// activate WooCommerce and then open an admin page would otherwise flake on fresh sites.
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );

// Records every WooCommerce email sent (whether or not delivery succeeds) in option
// gq_test_emails as [ { id, order_id, recipient } ], so tests can assert which emails went out.
add_action(
	'woocommerce_email_sent',
	static function ( $sent, $id, $email ): void {
		$log   = (array) get_option( 'gq_test_emails', [] );
		$log[] = [
			'id'        => (string) $id,
			'order_id'  => $email->object instanceof WC_Order ? $email->object->get_id() : null,
			'recipient' => (string) $email->get_recipient(),
		];
		update_option( 'gq_test_emails', $log, false );
	},
	10,
	3
);

add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			$log   = (array) get_option( 'gq_test_uninstall_http', [] );
			$log[] = $url;
			update_option( 'gq_test_uninstall_http', $log, false );
		}
		return $response;
	},
	PHP_INT_MAX,
	3
);
