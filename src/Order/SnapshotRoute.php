<?php
/**
 * The signed Order Snapshot REST route.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use GetQuick\Payments\Checkout\Gateway;
use GetQuick\Payments\Transport\Transport;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/gq-payments/v1/orders/{id}/snapshot?key={order key}, signed by the Payment Service.
 */
final class SnapshotRoute {

	/**
	 * Hooks the route into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoute' ] );
		add_filter( 'getquick_config_public_rest_routes', static fn( $routes ): array => OrderRoutes::allowAnonymous( $routes, 'GET' ) );
	}

	/**
	 * Registers the route.
	 */
	public function registerRoute(): void {
		register_rest_route(
			OrderRoutes::NAMESPACE,
			'/orders/(?P<id>\d+)/snapshot',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Transport::class, 'authorize' ],
				'callback'            => [ $this, 'respond' ],
				'args'                => [
					'id'  => [
						'type'     => 'integer',
						'required' => true,
					],
					'key' => [
						'type' => 'string',
					],
				],
			]
		);
	}

	/**
	 * Answers with the order's snapshot, or one generic 404 for any order it may not read.
	 *
	 * @param WP_REST_Request $request Verified request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function respond( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$orderId = (int) $request->get_param( 'id' );
		$key     = (string) $request->get_param( 'key' );
		// Locked like Payment State, so concurrent first snapshots mark Payment Start (and reduce stock) once.
		$reply = OrderLock::run( $orderId, fn(): WP_REST_Response|WP_Error => $this->respondLocked( $orderId, $key ) );
		return $reply ?? PaymentStateRoute::busy();
	}

	/**
	 * Builds the reply while holding the order's lock.
	 *
	 * @param int    $orderId Order ID.
	 * @param string $key     Order key from the request.
	 */
	private function respondLocked( int $orderId, string $key ): WP_REST_Response|WP_Error {
		// Loaded only once locked, so it reflects a Payment Start marked just before.
		$order = wc_get_order( $orderId );
		if ( ! Gateway::owns( $order )
			|| ! $order->key_is_valid( $key )
		) {
			return new WP_Error( 'gq_payments_order_not_found', __( 'Order not found.', 'gq-payments' ), [ 'status' => 404 ] );
		}
		// A cancelled order, or one that left Pending payment before Payment Start (for example
		// through WooCommerce's unpaid-order cancellation), can no longer start a payment.
		if ( $order->has_status( 'cancelled' ) || ( ! OrderGuards::paymentStarted( $order ) && ! $order->has_status( 'pending' ) ) ) {
			return new WP_Error( 'gq_payments_order_not_payable', __( 'This order can no longer be paid.', 'gq-payments' ), [ 'status' => 409 ] );
		}

		$snapshot = OrderSnapshot::of( $order );
		$this->markPaymentStart( $order, $snapshot['snapshot_revision'], $snapshot['totals']['total'] );

		return new WP_REST_Response( $snapshot );
	}

	/**
	 * The first snapshot of a Pending payment order marks Payment Start and holds its stock (ADR 0005).
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $revision Snapshot Revision just served.
	 * @param string   $total    Its total: the amount the Payment Service collects.
	 */
	private function markPaymentStart( WC_Order $order, string $revision, string $total ): void {
		if ( ! $order->has_status( 'pending' ) || '' !== (string) $order->get_meta( OrderMeta::PAYMENT_STARTED_AT ) ) {
			return;
		}
		$order->update_meta_data( OrderMeta::PAYMENT_STARTED_AT, (string) time() );
		$order->update_meta_data( OrderMeta::SNAPSHOT_REVISION, $revision );
		$order->update_meta_data( OrderMeta::SNAPSHOT_TOTAL, $total );
		$order->save();

		// Sets the order's stock-reduced flag, so payment_complete() won't reduce again and cancelling restores it.
		wc_maybe_reduce_stock_levels( $order->get_id() );
		// The Store API reserved this stock at checkout; it is now reduced, so drop the reservation.
		wc_release_stock_for_order( $order );
	}
}
