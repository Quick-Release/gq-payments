<?php
/**
 * Plugin Name: GQ Payments test availability
 * Description: Test-only control of which methods the gateway treats as eligible (through the gq_payments_method_eligible filter).
 *
 * Options that steer it:
 * - gq_test_eligible_methods: JSON array of method IDs (e.g. ["multibanco"]) treated as eligible;
 *   absent = the plugin's default (every known method while connected).
 *
 * @package GetQuick\Payments\Tests
 */

add_filter(
	'gq_payments_method_eligible',
	static function ( $eligible, $method ) {
		$methods = get_option( 'gq_test_eligible_methods', null );
		return is_array( $methods ) ? $eligible && in_array( $method, $methods, true ) : $eligible;
	},
	10,
	2
);
