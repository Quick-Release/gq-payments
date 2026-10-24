<?php
/**
 * WooCommerce behaviour that changes for gq_payments orders.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use GetQuick\Payments\Checkout\Gateway;
use WC_Order;

/**
 * Locks orders from Payment Start (ADR 0003).
 */
final class OrderGuards {

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'wc_order_is_editable', [ $this, 'lockAfterPaymentStart' ], 10, 2 );
	}

	/**
	 * Nobody may reprice an order a customer is already paying.
	 *
	 * @param bool          $editable Whether WooCommerce considers the order editable.
	 * @param WC_Order|null $order    Order.
	 */
	public function lockAfterPaymentStart( $editable, $order ): bool {
		return (bool) $editable && ! self::paymentStarted( $order );
	}

	/**
	 * Whether the order is a gq_payments order past Payment Start.
	 *
	 * @param mixed $order Order.
	 */
	public static function paymentStarted( $order ): bool {
		return $order instanceof WC_Order
			&& Gateway::ID === $order->get_payment_method()
			&& '' !== (string) $order->get_meta( OrderMeta::PAYMENT_STARTED_AT );
	}
}
