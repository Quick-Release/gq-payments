<?php
/**
 * Plugin lifecycle entry point.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

use GetQuick\Payments\Admin\PaymentsPage;

/**
 * Wires GQ Payments' modules into WordPress once requirements are met.
 */
final class Plugin {

	/**
	 * Registers plugin services when WordPress has loaded its plugins.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			( new PaymentsPage() )->register();
		}
	}
}
