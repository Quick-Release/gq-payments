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
	 */
	public function apply( WC_Order $order, PaymentState $state ): void {
		$previous      = (string) $order->get_meta( OrderMeta::REFUNDED_TOTAL );
		$previousMinor = '' === $previous ? 0 : PaymentState::minorUnits( $previous, wc_get_price_decimals() );

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
		$this->note( $order, $state, __( 'recorded.', 'gq-payments' ) );
	}

	/**
	 * Late Payment: flagged and noted for review, never fulfilled (no status change).
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function latePayment( WC_Order $order, PaymentState $state ): void {
		$this->note( $order, $state, __( 'recorded.', 'gq-payments' ) );
	}

	/**
	 * Adds a review note when the order changed after the snapshot the payment was collected for.
	 *
	 * @param WC_Order     $order Order.
	 * @param PaymentState $state State.
	 */
	private function noteRevisionMismatch( WC_Order $order, PaymentState $state ): void {
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
