<?php
/**
 * The refund step of applying a Payment State.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use WC_Order;

/**
 * Mirrors the verified cumulative refunded total onto WooCommerce (ADR 0004).
 *
 * PaymentStateMirror calls it once per newly applied version, after the status step and
 * inside the Payment State flag (so a status change it causes is not noted as manual),
 * before the order is saved. $previousMinor is the refunded total of the last applied
 * version; OrderMeta::REFUNDED_TOTAL already holds $state's total when it runs.
 */
interface RefundMirror {

	/**
	 * Mirrors $state's refunded total onto $order.
	 *
	 * @param WC_Order     $order         Order, locked for this Payment State.
	 * @param PaymentState $state         The version being applied.
	 * @param int          $previousMinor Refunded total of the last applied version, in minor units.
	 */
	public function mirror( WC_Order $order, PaymentState $state, int $previousMinor ): void;
}
