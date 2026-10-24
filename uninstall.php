<?php
/**
 * Removes GQ Payments settings when the plugin is deleted.
 *
 * Also drops the replay table of seen request IDs. Order payment history
 * (order meta and order notes) is financial record and
 * is always kept. Uninstall never contacts the Payment Service or any other
 * external service, and never iterates a network (GQ Payments is single-site).
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Every plugin option and transient uses the gq_payments_ prefix; the gateway's
// WooCommerce settings live in woocommerce_gq_payments_settings.
$gq_payments_patterns = [
	$wpdb->esc_like( 'gq_payments_' ) . '%',
	$wpdb->esc_like( '_transient_gq_payments_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_gq_payments_' ) . '%',
];

foreach ( $gq_payments_patterns as $gq_payments_pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall lookup by prefix; option names are not cached by prefix.
	$gq_payments_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $gq_payments_pattern ) );

	foreach ( $gq_payments_options as $gq_payments_option ) {
		delete_option( $gq_payments_option );
	}
}

delete_option( 'woocommerce_gq_payments_settings' );

// Seen request IDs. The plugin and its autoloader are not loaded during uninstall,
// so load the replay store directly; it owns its table's name and removal.
require_once __DIR__ . '/src/Transport/ReplayStore.php';
require_once __DIR__ . '/src/Transport/DatabaseReplayStore.php';
GetQuick\Payments\Transport\DatabaseReplayStore::uninstall();
