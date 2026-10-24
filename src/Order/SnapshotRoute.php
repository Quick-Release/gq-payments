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

	public const NAMESPACE = 'gq-payments/v1';

	/**
	 * Hooks the route into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoute' ] );
		add_filter( 'getquick_config_public_rest_routes', [ $this, 'allowAnonymous' ] );
	}

	/**
	 * Lets the Payment Service's signed (anonymous) GET through gq-config's restricted REST mode.
	 * gq-config matches exact routes or "/prefix/*" only, so per-order routes need the wildcard;
	 * Transport::authorize still guards every route under it.
	 *
	 * @param array<string, string[]> $routes Route or "/prefix/*" => allowed methods.
	 * @return array<string, string[]>
	 */
	public function allowAnonymous( $routes ): array {
		$routes             = (array) $routes;
		$pattern            = '/' . self::NAMESPACE . '/orders/*';
		$routes[ $pattern ] = array_values( array_unique( array_merge( (array) ( $routes[ $pattern ] ?? [] ), [ 'GET' ] ) ) );
		return $routes;
	}

	/**
	 * Registers the route.
	 */
	public function registerRoute(): void {
		register_rest_route(
			self::NAMESPACE,
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
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order instanceof WC_Order
			|| Gateway::ID !== $order->get_payment_method()
			|| ! $order->key_is_valid( (string) $request->get_param( 'key' ) )
		) {
			return new WP_Error( 'gq_payments_order_not_found', __( 'Order not found.', 'gq-payments' ), [ 'status' => 404 ] );
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
