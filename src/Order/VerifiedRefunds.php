<?php
/**
 * WooCommerce refunds on gq_payments orders come only from verified Payment State.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use Exception;
use GetQuick\Payments\Checkout\Gateway;
use WC_Order;
use WC_Order_Refund;

/**
 * Mirrors each increase in the verified cumulative refunded total as one WooCommerce refund
 * (ADR 0004), and refuses every other refund on gq_payments orders (ADR 0001): refunds move
 * money only in Banquinha, so a refund recorded anywhere else would move none.
 *
 * The increase is measured against the sum of the order's WooCommerce refunds, not the last
 * stored total, so WooCommerce's refunded total converges on the verified one.
 */
final class VerifiedRefunds implements RefundMirror {

	/** Argument to wc_create_refund() that marks the mirror's own refunds. */
	private const MARKER = 'gq_payments_verified_refund';

	/**
	 * How many mirrored refunds are being created in this request.
	 *
	 * @var int
	 */
	private static int $mirroring = 0;

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		// Throwing here makes wc_create_refund() delete the refund and return a WP_Error, which
		// the admin screen, the REST API and other plugins all receive.
		add_action( 'woocommerce_create_refund', [ $this, 'refuseUnverified' ], 10, 2 );
	}

	/**
	 * Mirrors $state's refunded total onto $order.
	 *
	 * @param WC_Order     $order         Order, locked for this Payment State.
	 * @param PaymentState $state         The version being applied.
	 * @param int          $previousMinor Refunded total of the last applied version, in minor units (unused: WooCommerce's own refunds are the reference).
	 */
	public function mirror( WC_Order $order, PaymentState $state, int $previousMinor ): void {
		$decimals = wc_get_price_decimals();
		$mirrored = PaymentState::minorUnits( wc_format_decimal( (string) $order->get_total_refunded(), $decimals ), $decimals );
		$increase = $state->refundedMinor - $mirrored;
		if ( $increase <= 0 ) {
			return;
		}

		$amount = PaymentState::decimal( $increase, $decimals );
		$total  = PaymentState::decimal( $state->refundedMinor, $decimals );
		// A Late Payment never changes the order's status (ADR 0004), so a full refund of one
		// leaves the order Cancelled instead of WooCommerce's usual move to Refunded.
		$keepStatus = 'yes' === $order->get_meta( OrderMeta::LATE_PAYMENT )
			? static fn( $status, $orderId ) => (int) $orderId === $order->get_id() ? '' : $status
			: null;
		if ( null !== $keepStatus ) {
			add_filter( 'woocommerce_order_fully_refunded_status', $keepStatus, 10, 2 );
		}
		++self::$mirroring;
		try {
			$refund = wc_create_refund(
				[
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => __( 'Refund made in Banquinha.', 'gq-payments' ),
					'line_items'     => [],
					'refund_payment' => false,
					'restock_items'  => false,
					self::MARKER     => true,
				]
			);
		} finally {
			--self::$mirroring;
			if ( null !== $keepStatus ) {
				remove_filter( 'woocommerce_order_fully_refunded_status', $keepStatus, 10 );
			}
		}

		if ( is_wp_error( $refund ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: refund amount with currency, 2: Payment State version, 3: WooCommerce's error message. */
					__( 'A refund of %1$s verified by the Payment Service (Payment State version %2$d) could not be recorded in WooCommerce: %3$s Review the order in Banquinha.', 'gq-payments' ),
					$amount . ' ' . $order->get_currency(),
					$state->version,
					$refund->get_error_message()
				)
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount with currency, 2: Payment State version, 3: cumulative refunded total with currency. */
				__( 'Refund of %1$s verified by the Payment Service (Payment State version %2$d; refunded total %3$s). Refunds are made in Banquinha; no money moved through WooCommerce and no stock was restored.', 'gq-payments' ),
				$amount . ' ' . $order->get_currency(),
				$state->version,
				$total . ' ' . $order->get_currency()
			)
		);
	}

	/**
	 * Refuses a refund on a gq_payments order unless the mirror is creating it.
	 *
	 * @param WC_Order_Refund $refund Refund about to be saved.
	 * @param array           $args   wc_create_refund() arguments.
	 * @throws Exception When the refund did not come from verified Payment State.
	 */
	public function refuseUnverified( $refund, $args ): void {
		if ( self::$mirroring > 0 && is_array( $args ) && true === ( $args[ self::MARKER ] ?? null ) ) {
			return;
		}
		$order = $refund instanceof WC_Order_Refund ? wc_get_order( $refund->get_parent_id() ) : false;
		if ( ! Gateway::owns( $order ) ) {
			return;
		}
		throw new Exception( esc_html( self::refusal() ) );
	}

	/**
	 * Why WooCommerce refunds are unavailable on gq_payments orders.
	 */
	public static function refusal(): string {
		return __( 'Refunds for this order are made in Banquinha. WooCommerce records them here once the Payment Service has verified them.', 'gq-payments' );
	}
}
