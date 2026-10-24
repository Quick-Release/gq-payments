<?php
/**
 * Refund mirroring placeholder.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use WC_Order;

/**
 * Records the verified refunded total only (in OrderMeta::REFUNDED_TOTAL) and creates
 * no WooCommerce refunds. Replaced by real refund mirroring in a later slice.
 */
final class NoRefundMirror implements RefundMirror {

	/**
	 * Does nothing.
	 *
	 * @param WC_Order     $order         Order.
	 * @param PaymentState $state         The version being applied.
	 * @param int          $previousMinor Previous refunded total, in minor units.
	 */
	public function mirror( WC_Order $order, PaymentState $state, int $previousMinor ): void {
	}
}
