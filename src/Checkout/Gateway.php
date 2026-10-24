<?php
/**
 * The hidden gq_payments WooCommerce gateway.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

use GetQuick\Payments\Order\OrderMeta;
use WC_Payment_Gateway;

/**
 * Creates pending orders for the Payment Service to collect (ADR 0003).
 *
 * It has no settings and no refunds support, and it never calls the Payment Service:
 * the storefront starts payment, and the service then pulls the Order Snapshot.
 */
final class Gateway extends WC_Payment_Gateway {

	public const ID = 'gq_payments';

	/**
	 * Connection state and Eligible Methods.
	 *
	 * @var Availability
	 */
	private Availability $checkoutAvailability;

	/**
	 * Builds the gateway.
	 *
	 * @param Availability $availability Connection state and Eligible Methods.
	 */
	public function __construct( Availability $availability ) {
		$this->checkoutAvailability = $availability;
		$this->id                   = self::ID;
		$this->method_title         = __( 'GETQUICK Payments', 'gq-payments' );
		$this->method_description   = __( 'Payments collected by the GETQUICK Payment Service through the storefront checkout.', 'gq-payments' );
		$this->title                = $this->method_title;
		$this->has_fields           = false;
		$this->supports             = [ 'products' ];
		// The Payment Service owns which methods are offered; WooCommerce's own toggle does not apply.
		$this->enabled = 'yes';
	}

	/**
	 * Available while the site is connected and at least one method is eligible.
	 */
	public function is_available(): bool {
		if ( ! $this->checkoutAvailability->isConnected() ) {
			return false;
		}
		foreach ( Methods::ids() as $method ) {
			if ( $this->checkoutAvailability->isMethodEligible( $method ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Leaves the order in Pending payment, titled with the chosen method; calls nothing external.
	 *
	 * @param int $order_id Order ID.
	 * @return array{result: string, redirect: string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$title = Methods::title( (string) $order->get_meta( OrderMeta::METHOD ) );
			if ( null !== $title ) {
				$order->set_payment_method_title( $title );
				$order->save();
			}
		}
		return [
			'result'   => 'success',
			'redirect' => '',
		];
	}
}
