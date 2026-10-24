<?php
/**
 * GQ Payments plugin bootstrap.
 *
 * Plugin Name: GQ Payments
 * Plugin URI: https://github.com/Quick-Release/gq-payments
 * Description: Connects WooCommerce checkout to GETQUICK's Payment Service.
 * Version: 0.0.1
 * Requires at least: 7.0
 * Requires PHP: 8.4.1
 * Requires Plugins: woocommerce, getquick-ecommerce
 * WC requires at least: 10.9
 * WC tested up to: 11.2
 * Author: GETQUICK
 * Author URI: https://getquick.io
 * License: Proprietary
 * Text Domain: gq-payments
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use GetQuick\Payments\Plugin;
use GetQuick\Payments\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GETQUICK_PAYMENTS_VERSION', '0.0.1' );
define( 'GETQUICK_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'GETQUICK_PAYMENTS_PLUGIN_DIR', __DIR__ );

// Composer sites supply the autoloader; standalone installs resolve classes from src/.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'GetQuick\\Payments\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// GQ Payments supports single sites only.
register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die(
				esc_html__( 'GQ Payments supports single sites only. Activate it on each site instead of network-wide.', 'gq-payments' ),
				esc_html__( 'Plugin activation refused', 'gq-payments' ),
				[ 'back_link' => true ]
			);
		}
	}
);

// Declared even when requirements fail, so WooCommerce never reports GQ Payments as incompatible.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', GETQUICK_PAYMENTS_PLUGIN_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$requirements = new Requirements();
		$failures     = $requirements->currentFailures();
		if ( [] !== $failures ) {
			$requirements->registerAdminNotice( $failures );
			return;
		}

		( new Plugin() )->boot();
	}
);
