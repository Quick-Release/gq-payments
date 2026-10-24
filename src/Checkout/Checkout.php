<?php
/**
 * Hooks the hidden gateway into WooCommerce checkout.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;
use GetQuick\Payments\Order\OrderMeta;
use InvalidArgumentException;

/**
 * Registers gq_payments and validates the method a Store API checkout chose.
 */
final class Checkout {

	/**
	 * Gateway instance, built on first use.
	 *
	 * @var Gateway|null
	 */
	private ?Gateway $gateway = null;

	/**
	 * Builds the checkout wiring.
	 *
	 * @param Availability $availability Connection state and Eligible Methods.
	 */
	public function __construct( private readonly Availability $availability ) {
	}

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', [ $this, 'addGateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'hideOutsideStoreApi' ] );
		// Before the Store API's legacy bridge (999), which then calls Gateway::process_payment().
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'acceptMethod' ], 10, 2 );
	}

	/**
	 * Adds the gateway to WooCommerce's list.
	 *
	 * @param array<mixed> $gateways Gateway class names or instances.
	 * @return array<mixed>
	 */
	public function addGateway( $gateways ): array {
		$this->gateway ??= new Gateway( $this->availability );
		$gateways        = (array) $gateways;
		$gateways[]      = $this->gateway;
		return $gateways;
	}

	/**
	 * Offers gq_payments only to Store API requests (the headless storefront), never on
	 * WordPress's classic checkout or pay-for-order pages. Block checkout never shows it
	 * either: the gateway registers no block integration.
	 *
	 * @param array<string, mixed> $gateways Available gateways by ID.
	 * @return array<string, mixed>
	 */
	public function hideOutsideStoreApi( $gateways ): array {
		$gateways = (array) $gateways;
		if ( ! WC()->is_store_api_request() ) {
			unset( $gateways[ Gateway::ID ] );
		}
		return $gateways;
	}

	/**
	 * Requires an eligible method in payment_data and records it on the order.
	 *
	 * @param PaymentContext $context Store API payment context.
	 * @param PaymentResult  $result  Store API payment result (unused; the legacy bridge fills it).
	 * @throws InvalidArgumentException When the method is missing or not eligible (Store API answers 400).
	 */
	public function acceptMethod( $context, $result ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Action signature.
		if ( ! $context instanceof PaymentContext || Gateway::ID !== $context->payment_method ) {
			return;
		}
		$method = (string) ( $context->payment_data[ Methods::PAYMENT_DATA_KEY ] ?? '' );
		if ( ! Methods::isKnown( $method ) || ! $this->availability->isMethodEligible( $method ) ) {
			throw new InvalidArgumentException( esc_html__( 'This payment method is not available.', 'gq-payments' ) );
		}
		$context->order->update_meta_data( OrderMeta::METHOD, $method );
		$context->order->save();
	}
}
