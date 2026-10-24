<?php
/**
 * Plugin Name: gq-config stub (tests)
 * Description: Emulates the parts of the private gq-config plugin GQ Payments integrates with. Set option gq_test_gqconfig_menu to "off" to remove the GETQUICK menu.
 *
 * @package GetQuick\Payments\Tests
 */

add_action(
	'admin_menu',
	static function (): void {
		if ( 'off' === get_option( 'gq_test_gqconfig_menu' ) ) {
			return;
		}
		// Same registration as gq-config/includes/class-admin-page.php.
		add_menu_page(
			'GETQUICK Options',
			'GETQUICK',
			'manage_options',
			'getquick-options',
			static function (): void {
				echo '<div class="wrap"><h1>GETQUICK Options</h1></div>';
			},
			'',
			56
		);
	}
);
