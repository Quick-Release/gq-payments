<?php
/**
 * Plugin lifecycle entry point.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

use GetQuick\Payments\Admin\LatePaymentNotice;
use GetQuick\Payments\Admin\PaymentsPage;
use GetQuick\Payments\Admin\RefundsInBanquinha;
use GetQuick\Payments\Checkout\Checkout;
use GetQuick\Payments\Connection\Connection;
use GetQuick\Payments\Order\OrderGuards;
use GetQuick\Payments\Order\PaymentStateMirror;
use GetQuick\Payments\Order\PaymentStateRoute;
use GetQuick\Payments\Order\SnapshotRoute;
use GetQuick\Payments\Order\StatusSource;
use GetQuick\Payments\Order\VerifiedRefunds;

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
		$refunds = new VerifiedRefunds();
		$refunds->register();
		( new PaymentStateRoute( new PaymentStateMirror( $refunds ) ) )->register();
		( new StatusSource() )->register();

		if ( is_admin() ) {
			( new PaymentsPage() )->register();
			( new LatePaymentNotice() )->register();
			( new RefundsInBanquinha() )->register();
		}
	}
}
