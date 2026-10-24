<?php
/**
 * Plugin Name: GQ Payments test environment
 * Description: Test-only observers. Records outbound HTTP made while a plugin is being uninstalled in option gq_test_uninstall_http.
 *
 * @package GetQuick\Payments\Tests
 */

// A fresh WooCommerce install sets _wc_activation_redirect for 30 s, and the first
// admin page loaded in that time redirects (302) to the onboarding wizard. Tests that
// activate WooCommerce and then open an admin page would otherwise flake on fresh sites.
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );

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
