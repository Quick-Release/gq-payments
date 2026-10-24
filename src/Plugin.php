<?php
/**
 * Plugin lifecycle entry point.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

use GetQuick\Payments\Admin\PaymentsPage;
use GetQuick\Payments\Checkout\Checkout;
use GetQuick\Payments\Connection\Connection;
use GetQuick\Payments\Order\OrderGuards;
use GetQuick\Payments\Order\SnapshotRoute;

/**
 * Wires GQ Payments' modules into WordPress once requirements are met.
 */
final class Plugin {

	/**
	 * Registers plugin services when WordPress has loaded its plugins.
	 */
	public function boot(): void {
		( new Checkout( Connection::fromWordPress() ) )->register();
		( new SnapshotRoute() )->register();
		( new OrderGuards() )->register();

		if ( is_admin() ) {
			( new PaymentsPage() )->register();
		}
	}
}
