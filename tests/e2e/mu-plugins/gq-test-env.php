<?php
/**
 * Plugin Name: GQ Payments test environment
 * Description: Test-only observers. Records outbound HTTP made while a plugin is being uninstalled in option gq_test_uninstall_http.
 *
 * @package GetQuick\Payments\Tests
 */

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
