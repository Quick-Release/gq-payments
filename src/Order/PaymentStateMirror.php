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
		$previousMinor = '' === $previous ? 0 : Money::toMinor( $previous );
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
		$order->update_meta_data( OrderMeta::REFUNDED_TOTAL, Money::fromMinor( $state->refundedMinor ) );

		StatusSource::applyingPaymentState(
			function () use ( $order, $state, $previousMinor ): void {
				match ( $state->state ) {
					PaymentState::PAID         => $this->paid( $order, $state ),
					PaymentState::EXPIRED      => $this->expired( $order, $state ),
					PaymentState::LATE_PAYMENT => $this->latePayment( $order, $state ),
					default                    => $order->add_order_note(
						/* translators: 1: Payment State, e.g. "pending", 2: version number, 3: attempt ID, or "none". */
						sprintf( __( 'Payment State "%1$s" (version %2$d, attempt %3$s): recorded.', 'gq-payments' ), ...self::facts( $state ) )
					),
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
			$order->add_order_note(
				sprintf(
					/* translators: 1: Payment State, e.g. "paid", 2: version number, 3: attempt ID, or "none", 4: order status name. */
					__( 'Payment State "%1$s" (version %2$d, attempt %3$s): recorded; the order is %4$s, so its status was left unchanged.', 'gq-payments' ),
					...[ ...self::facts( $state ), wc_get_order_status_name( $order->get_status() ) ]
				)
			);
			$this->noteRevisionMismatch( $order, $state );
			return;
		}

		$order->add_order_note(
			/* translators: 1: Payment State, e.g. "paid", 2: version number, 3: attempt ID. */
			sprintf( __( 'Payment State "%1$s" (version %2$d, attempt %3$s): payment verified by the Payment Service.', 'gq-payments' ), ...self::facts( $state ) )
		);
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
			$order->update_status(
				'cancelled',
				// The trailing space separates this note from WooCommerce's own status-change sentence.
				sprintf(
					/* translators: 1: Payment State, e.g. "expired", 2: version number, 3: attempt ID, or "none". */
					__( 'Payment State "%1$s" (version %2$d, attempt %3$s): the payment window closed.', 'gq-payments' ),
					...self::facts( $state )
				) . ' '
			);
			return;
		}
		$order->add_order_note(
			$order->has_status( 'cancelled' )
				? sprintf(
					/* translators: 1: Payment State, e.g. "expired", 2: version number, 3: attempt ID, or "none". */
					__( 'Payment State "%1$s" (version %2$d, attempt %3$s): recorded; the order was already cancelled.', 'gq-payments' ),
					...self::facts( $state )
				)
				: sprintf(
					/* translators: 1: Payment State, e.g. "expired", 2: version number, 3: attempt ID, or "none", 4: order status name. */
					__( 'Payment State "%1$s" (version %2$d, attempt %3$s): recorded; the order is %4$s, so it was not cancelled. Review it in Banquinha.', 'gq-payments' ),
					...[ ...self::facts( $state ), wc_get_order_status_name( $order->get_status() ) ]
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
			sprintf(
				/* translators: 1: Payment State, e.g. "late_payment", 2: version number, 3: attempt ID. */
				__( 'Payment received after cancellation — review in Banquinha. Do not fulfil this order before deciding whether to fulfil or refund it. Payment State "%1$s" (version %2$d, attempt %3$s): Late Payment recorded; the order status was not changed.', 'gq-payments' ),
				...self::facts( $state )
			)
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
		$currentTotal = Money::format( Money::toMinor( (string) $order->get_total() ), $order->get_currency() );
		$minor        = self::collectedMinor( $order, $state );
		$collected    = null === $minor ? null : Money::format( $minor, $order->get_currency() );
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
		$total = (string) $order->get_meta( OrderMeta::SNAPSHOT_TOTAL );
		if ( '' !== $total && hash_equals( (string) $order->get_meta( OrderMeta::SNAPSHOT_REVISION ), $state->snapshotRevision ) ) {
			return Money::toMinor( $total );
		}
		if ( hash_equals( OrderSnapshot::revision( $order ), $state->snapshotRevision ) ) {
			return Money::toMinor( (string) $order->get_total() );
		}
		return null;
	}

	/**
	 * The placeholders every Payment State note starts with: state, version and attempt ID.
	 *
	 * @param PaymentState $state State.
	 * @return array{0: string, 1: int, 2: string}
	 */
	private static function facts( PaymentState $state ): array {
		return [
			$state->state,
			$state->version,
			/* translators: shown in place of an attempt ID when the Payment State has none. */
			'' === $state->attemptId ? __( 'none', 'gq-payments' ) : $state->attemptId,
		];
	}
}
