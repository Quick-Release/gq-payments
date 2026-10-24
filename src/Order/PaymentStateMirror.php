<?php
/**
 * Applies a Payment State to its WooCommerce order.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use WC_Order;

/**
 * Mirrors the Payment Service's verdict onto the order (ADR 0004). The store never decides
 * payment itself: every status change made here is noted with its version and attempt ID.
 */
final class PaymentStateMirror {

	/**
	 * Builds the mirror.
	 *
	 * @param RefundMirror $refunds Mirrors the verified refunded total.
	 */
	public function __construct( private readonly RefundMirror $refunds ) {}

	/**
	 * Applies a version newer than the order's last applied one. The caller holds the order's lock.
	 *
	 * @param WC_Order     $order Order past Payment Start.
	 * @param PaymentState $state Newer version.
	 * @throws InvalidPaymentState When its refunded total is impossible for this order; nothing is changed.
	 */
	public function apply( WC_Order $order, PaymentState $state ): void {
		$previous      = (string) $order->get_meta( OrderMeta::REFUNDED_TOTAL );
		$previousMinor = '' === $previous ? 0 : PaymentState::minorUnits( $previous, wc_get_price_decimals() );
		$collected     = self::collectedMinor( $order, $state );
		// The total is cumulative: refunds are never undone, and never exceed what was collected.
		if ( $state->refundedMinor < $previousMinor || ( null !== $collected && $state->refundedMinor > $collected ) ) {
			throw new InvalidPaymentState( 'refunded_total' );
		}

		// Set first, so the save inside any status change below stores them with it.
		$order->update_meta_data( OrderMeta::STATE_VERSION, (string) $state->version );
		if ( '' !== $state->attemptId ) {
			$order->update_meta_data( OrderMeta::ATTEMPT_ID, $state->attemptId );
		}
		$order->update_meta_data( OrderMeta::REFUNDED_TOTAL, PaymentState::decimal( $state->refundedMinor, wc_get_price_decimals() ) );

		StatusSource::applyingPaymentState(
			function () use ( $order, $state, $previousMinor ): void {
				match ( $state->state ) {
					PaymentState::PAID         => $this->paid( $order, $state ),
					PaymentState::EXPIRED      => $this->expired( $order, $state ),
					PaymentState::LATE_PAYMENT => $this->latePayment( $order, $state ),
					default                    => $this->note( $order, $state, __( 'recorded.', 'gq-payments' ) ),
				};
				$this->refunds->mirror( $order, $state, $previousMinor );
				$order->save();
			}
		);
	}

	/**
	 * Paid: completes a Pending payment order; a cancelled one has received a Late Payment.
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function paid( WC_Order $order, PaymentState $state ): void {
		if ( $order->has_status( 'cancelled' ) ) {
			$this->latePayment( $order, $state );
			return;
		}
		if ( ! $order->has_status( 'pending' ) ) {
			$this->note(
				$order,
				$state,
				sprintf(
					/* translators: %s: order status name. */
					__( 'recorded; the order is %s, so its status was left unchanged.', 'gq-payments' ),
					wc_get_order_status_name( $order->get_status() )
				)
			);
			$this->noteRevisionMismatch( $order, $state );
			return;
		}

		$this->note( $order, $state, __( 'payment verified by the Payment Service.', 'gq-payments' ) );
		$order->payment_complete( $state->attemptId );
		$this->noteRevisionMismatch( $order, $state );
	}

	/**
	 * Expired: cancels a Pending payment order, which restores the stock held since Payment Start.
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function expired( WC_Order $order, PaymentState $state ): void {
		if ( $order->has_status( 'pending' ) ) {
			// WooCommerce restores the stock reduced at Payment Start (the order's stock-reduced flag is set).
			$order->update_status( 'cancelled', self::describe( $state ) . ' ' . __( 'the payment window closed.', 'gq-payments' ) . ' ' );
			return;
		}
		$this->note(
			$order,
			$state,
			$order->has_status( 'cancelled' )
				? __( 'recorded; the order was already cancelled.', 'gq-payments' )
				: sprintf(
					/* translators: %s: order status name. */
					__( 'recorded; the order is %s, so it was not cancelled. Review it in Banquinha.', 'gq-payments' ),
					wc_get_order_status_name( $order->get_status() )
				)
		);
	}

	/**
	 * Late Payment: flagged and noted for review, never fulfilled (no status change).
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function latePayment( WC_Order $order, PaymentState $state ): void {
		$order->update_meta_data( OrderMeta::LATE_PAYMENT, 'yes' );
		$order->add_order_note(
			__( 'Payment received after cancellation — review in Banquinha. Do not fulfil this order before deciding whether to fulfil or refund it.', 'gq-payments' )
			. ' ' . self::describe( $state ) . ' ' . __( 'Late Payment recorded; the order status was not changed.', 'gq-payments' )
		);
	}

	/**
	 * Adds a review note when the order changed after the snapshot the payment was collected for.
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function noteRevisionMismatch( WC_Order $order, PaymentState $state ): void {
		$current = OrderSnapshot::revision( $order );
		if ( hash_equals( $current, $state->snapshotRevision ) ) {
			return;
		}
		$currentTotal = self::money( (string) $order->get_total(), $order );
		$minor        = self::collectedMinor( $order, $state );
		$collected    = null === $minor ? null : PaymentState::decimal( $minor, wc_get_price_decimals() ) . ' ' . $order->get_currency();
		$order->add_order_note(
			null === $collected
				? sprintf(
					/* translators: 1: short Snapshot Revision, 2: current order total with currency. */
					__( 'The order changed after the snapshot this payment was collected for: collected the total of Snapshot Revision %1$s, current total %2$s — review.', 'gq-payments' ),
					substr( $state->snapshotRevision, 0, 12 ),
					$currentTotal
				)
				: sprintf(
					/* translators: 1: amount collected with currency, 2: current order total with currency. */
					__( 'The order changed after the snapshot this payment was collected for: collected %1$s, current total %2$s — review.', 'gq-payments' ),
					$collected,
					$currentTotal
				)
		);
	}

	/**
	 * The amount collected in minor units, when the payment's Snapshot Revision is known:
	 * the one taken at Payment Start, or the order's current one.
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private static function collectedMinor( WC_Order $order, PaymentState $state ): ?int {
		$decimals = wc_get_price_decimals();
		$total    = (string) $order->get_meta( OrderMeta::SNAPSHOT_TOTAL );
		if ( '' !== $total && hash_equals( (string) $order->get_meta( OrderMeta::SNAPSHOT_REVISION ), $state->snapshotRevision ) ) {
			return PaymentState::minorUnits( wc_format_decimal( $total, $decimals ), $decimals );
		}
		if ( hash_equals( OrderSnapshot::revision( $order ), $state->snapshotRevision ) ) {
			return PaymentState::minorUnits( wc_format_decimal( (string) $order->get_total(), $decimals ), $decimals );
		}
		return null;
	}

	/**
	 * E.g. "20.00 EUR": plain text, independent of the store's price format.
	 *
	 * @param string   $amount Amount.
	 * @param WC_Order $order  Order (for its currency).
	 */
	private static function money( string $amount, WC_Order $order ): string {
		return wc_format_decimal( $amount, wc_get_price_decimals() ) . ' ' . $order->get_currency();
	}

	/**
	 * Notes a Payment State with its version and attempt ID.
	 *
	 * @param WC_Order     $order   Order.
	 * @param PaymentState $state   State.
	 * @param string       $outcome What it did, e.g. "recorded.".
	 */
	private function note( WC_Order $order, PaymentState $state, string $outcome ): void {
		$order->add_order_note( self::describe( $state ) . ' ' . $outcome );
	}

	/**
	 * E.g. 'Payment State "paid" (version 3, attempt att_123):'.
	 *
	 * @param PaymentState $state State.
	 */
	private static function describe( PaymentState $state ): string {
		return sprintf(
			/* translators: 1: Payment State, 2: version number, 3: attempt ID. */
			__( 'Payment State "%1$s" (version %2$d, attempt %3$s):', 'gq-payments' ),
			$state->state,
			$state->version,
			'' === $state->attemptId ? __( 'none', 'gq-payments' ) : $state->attemptId
		);
	}
}
