<?php
/**
 * The Order Snapshot: the totals the Payment Service freezes before collecting payment.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;

/**
 * Builds an order's snapshot and Snapshot Revision from WooCommerce's own totals (ADR 0003).
 *
 * Totals only: no line items and no customer data.
 */
final class OrderSnapshot {

	/**
	 * The snapshot body.
	 *
	 * @param WC_Order $order Order.
	 * @return array{order_id: int, currency: string, status: string, totals: array<string, string>, snapshot_revision: string}
	 */
	public static function of( WC_Order $order ): array {
		$totals = self::totals( $order );
		return [
			'order_id'          => $order->get_id(),
			'currency'          => $order->get_currency(),
			'status'            => $order->get_status(),
			'totals'            => $totals,
			'snapshot_revision' => self::revisionOf( $order->get_id(), $order->get_currency(), $totals ),
		];
	}

	/**
	 * The order's current Snapshot Revision: changes whenever the currency or any total changes.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function revision( WC_Order $order ): string {
		return self::revisionOf( $order->get_id(), $order->get_currency(), self::totals( $order ) );
	}

	/**
	 * Money amounts as decimal strings in the store's price precision.
	 *
	 * @param WC_Order $order Order.
	 * @return array{items_net: string, items_tax: string, shipping_net: string, shipping_tax: string, fees_net: string, fees_tax: string, total: string}
	 */
	private static function totals( WC_Order $order ): array {
		$itemsNet = 0.0;
		$itemsTax = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$itemsNet += (float) $item->get_total();
				$itemsTax += (float) $item->get_total_tax();
			}
		}
		$feesNet = 0.0;
		$feesTax = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( $fee instanceof WC_Order_Item_Fee ) {
				$feesNet += (float) $fee->get_total();
				$feesTax += (float) $fee->get_total_tax();
			}
		}

		return array_map(
			static fn( float $amount ): string => (string) wc_format_decimal( $amount, wc_get_price_decimals() ),
			[
				'items_net'    => $itemsNet,
				'items_tax'    => $itemsTax,
				'shipping_net' => (float) $order->get_shipping_total(),
				'shipping_tax' => (float) $order->get_shipping_tax(),
				'fees_net'     => $feesNet,
				'fees_tax'     => $feesTax,
				'total'        => (float) $order->get_total(),
			]
		);
	}

	/**
	 * SHA-256 (hex) over the order ID, currency and totals.
	 *
	 * @param int                   $orderId  Order ID.
	 * @param string                $currency Currency code.
	 * @param array<string, string> $totals   Snapshot totals.
	 */
	private static function revisionOf( int $orderId, string $currency, array $totals ): string {
		return hash( 'sha256', (string) wp_json_encode( [ $orderId, $currency, $totals ] ) );
	}
}
