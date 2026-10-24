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

		return new WP_REST_Response( OrderSnapshot::of( $order ) );
	}
}
