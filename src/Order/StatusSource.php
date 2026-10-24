<?php
/**
 * Where a gq_payments order's status change came from.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use GetQuick\Payments\Checkout\Gateway;
use WC_Order;

/**
 * Payment State changes are noted by the mirror; every other status change on a
 * gq_payments order (admin screen, REST API, CLI, other plugins) is noted as manual,
 * so it is never mistaken for proof of payment.
 */
final class StatusSource {

	/**
	 * How many Payment State applications are running in this request.
	 *
	 * @var int
	 */
	private static int $applying = 0;

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'noteManualChange' ], 10, 4 );
	}

	/**
	 * Runs $change as a Payment State change: status changes inside it are not noted as manual.
	 *
	 * @template T
	 * @param callable(): T $change Applies Payment State.
	 * @return T
	 */
	public static function applyingPaymentState( callable $change ): mixed {
		++self::$applying;
		try {
			return $change();
		} finally {
			--self::$applying;
		}
	}

	/**
	 * Notes a status change that did not come from Payment State.
	 *
	 * @param int           $orderId Order ID.
	 * @param string        $from    Previous status, without "wc-".
	 * @param string        $to      New status, without "wc-".
	 * @param WC_Order|null $order   Order.
	 */
	public function noteManualChange( $orderId, $from, $to, $order = null ): void {
		if ( self::$applying > 0
			|| ! $order instanceof WC_Order
			|| Gateway::ID !== $order->get_payment_method()
			// The Store API's own checkout step (draft → Pending payment), not a status decision.
			|| 'checkout-draft' === $from
		) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: order status name, e.g. "Processing". */
				__( 'Status set manually to %s — not payment evidence. Only Payment State from the Payment Service proves payment.', 'gq-payments' ),
				wc_get_order_status_name( (string) $to )
			)
		);
	}
}
