<?php
/**
 * The signed Payment State REST route.
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
 * POST /wp-json/gq-payments/v1/orders/{id}/payment-state, signed by the Payment Service (ADR 0004).
 */
final class PaymentStateRoute {

	/**
	 * Seconds to wait for another Payment State of the same order to finish.
	 */
	private const LOCK_TIMEOUT = 10;

	/**
	 * Builds the route.
	 *
	 * @param PaymentStateMirror $mirror Applies newer versions.
	 */
	public function __construct( private readonly PaymentStateMirror $mirror ) {}

	/**
	 * Hooks the route into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoute' ] );
		add_filter( 'getquick_config_public_rest_routes', [ $this, 'allowAnonymous' ] );
	}

	/**
	 * Lets the Payment Service's signed (anonymous) POST through gq-config's restricted REST mode,
	 * merged into the same "/gq-payments/v1/orders/*" entry as the snapshot route's GET.
	 *
	 * @param array<string, string[]> $routes Route or "/prefix/*" => allowed methods.
	 * @return array<string, string[]>
	 */
	public function allowAnonymous( $routes ): array {
		$routes             = (array) $routes;
		$pattern            = '/' . SnapshotRoute::NAMESPACE . '/orders/*';
		$routes[ $pattern ] = array_values( array_unique( array_merge( (array) ( $routes[ $pattern ] ?? [] ), [ 'POST' ] ) ) );
		return $routes;
	}

	/**
	 * Registers the route.
	 */
	public function registerRoute(): void {
		register_rest_route(
			SnapshotRoute::NAMESPACE,
			'/orders/(?P<id>\d+)/payment-state',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Transport::class, 'authorize' ],
				'callback'            => [ $this, 'receive' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Applies the message if its version is newer than the order's; repeats and older versions
	 * are acknowledged with no effect.
	 *
	 * @param WP_REST_Request $request Verified request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$orderId = (int) $request->get_param( 'id' );
		try {
			$state = PaymentState::fromPayload( json_decode( $request->get_body(), true ), wc_get_price_decimals() );
		} catch ( InvalidPaymentState $invalid ) {
			return self::invalid( $invalid->getMessage() );
		}

		if ( ! self::lock( $orderId ) ) {
			return new WP_Error( 'gq_payments_busy', __( 'Try again later.', 'gq-payments' ), [ 'status' => 503 ] );
		}
		try {
			// Loaded only once locked, so it reflects any Payment State applied just before.
			$order = wc_get_order( $orderId );
			if ( ! $order instanceof WC_Order || Gateway::ID !== $order->get_payment_method() ) {
				return new WP_Error( 'gq_payments_order_not_found', __( 'Order not found.', 'gq-payments' ), [ 'status' => 404 ] );
			}
			if ( ! OrderGuards::paymentStarted( $order ) ) {
				return new WP_Error( 'gq_payments_payment_not_started', __( 'Payment has not started for this order.', 'gq-payments' ), [ 'status' => 409 ] );
			}

			$applied = (int) $order->get_meta( OrderMeta::STATE_VERSION );
			if ( $state->version <= $applied ) {
				return self::acknowledge( $orderId, $applied, false );
			}

			try {
				$this->mirror->apply( $order, $state );
			} catch ( InvalidPaymentState $invalid ) {
				return self::invalid( $invalid->getMessage() );
			}
			return self::acknowledge( $orderId, $state->version, true );
		} finally {
			self::unlock( $orderId );
		}
	}

	/**
	 * 200 reply.
	 *
	 * @param int  $orderId Order ID.
	 * @param int  $version The order's last applied version.
	 * @param bool $applied Whether this message changed the order.
	 */
	private static function acknowledge( int $orderId, int $version, bool $applied ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'order_id' => $orderId,
				'version'  => $version,
				'applied'  => $applied,
			]
		);
	}

	/**
	 * 400 reply naming the offending field.
	 *
	 * @param string $field Field name.
	 */
	private static function invalid( string $field ): WP_Error {
		return new WP_Error(
			'gq_payments_invalid_payment_state',
			__( 'Invalid Payment State.', 'gq-payments' ),
			[
				'status' => 400,
				'field'  => $field,
			]
		);
	}

	/**
	 * Serialises Payment State per order across requests with a MySQL named lock,
	 * which works the same under HPOS and legacy order storage.
	 *
	 * @param int $orderId Order ID.
	 */
	private static function lock( int $orderId ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::lockName( $orderId ), self::LOCK_TIMEOUT ) );
	}

	/**
	 * Releases the order's lock.
	 *
	 * @param int $orderId Order ID.
	 */
	private static function unlock( int $orderId ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lockName( $orderId ) ) );
	}

	/**
	 * Lock name, unique per database prefix and order (MySQL allows 64 characters).
	 *
	 * @param int $orderId Order ID.
	 */
	private static function lockName( int $orderId ): string {
		global $wpdb;
		return substr( 'gq_payments_state_' . md5( DB_NAME . $wpdb->prefix ) . '_' . $orderId, 0, 64 );
	}
}
