<?php
/**
 * GQ Payments plugin bootstrap.
 *
 * Plugin Name: GQ Payments
 * Plugin URI: https://github.com/Quick-Release/gq-payments
 * Description: Payment provider integration for GETQUICK sites.
 * Version: 0.0.1
 * Requires at least: 7.0
 * Requires PHP: 8.4.1
 * Author: GETQUICK
 * Author URI: https://getquick.io
 * License: Proprietary
 * Text Domain: gq-payments
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

use GetQuick\Payments\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GETQUICK_PAYMENTS_VERSION', '0.0.1' );
define( 'GETQUICK_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'GETQUICK_PAYMENTS_PLUGIN_DIR', __DIR__ );

// Composer sites supply the autoloader; standalone installs load the entry class.
if ( ! class_exists( Plugin::class ) ) {
	require_once __DIR__ . '/src/Plugin.php';
}

add_action(
	'plugins_loaded',
	static function (): void {
		( new Plugin() )->boot();
	}
);
