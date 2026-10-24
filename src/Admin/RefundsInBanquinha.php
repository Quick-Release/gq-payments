<?php
/**
 * The order screen's refund UI on gq_payments orders.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Admin;

use GetQuick\Payments\Checkout\Gateway;
use GetQuick\Payments\Order\VerifiedRefunds;
use WC_Order;

/**
 * Replaces WooCommerce's Refund button on gq_payments orders with a pointer to Banquinha,
 * where refunds are made. Refunds sent anyway are refused by VerifiedRefunds.
 */
final class RefundsInBanquinha {

	/**
	 * Hooks into the order screen.
	 */
	public function register(): void {
		add_filter( 'woocommerce_admin_order_should_render_refunds', [ $this, 'hideRefunds' ], 10, 3 );
		add_action( 'woocommerce_order_item_add_action_buttons', [ $this, 'render' ] );
	}

	/**
	 * No refund UI on gq_payments orders.
	 *
	 * @param bool          $render  Whether WooCommerce would render it.
	 * @param int           $orderId Order ID.
	 * @param WC_Order|null $order   Order.
	 */
	public function hideRefunds( $render, $orderId, $order = null ): bool {
		return (bool) $render && ! Gateway::owns( $order );
	}

	/**
	 * Prints the pointer where the Refund button would be.
	 *
	 * @param WC_Order|null $order Order.
	 */
	public function render( $order ): void {
		if ( ! Gateway::owns( $order ) ) {
			return;
		}
		?>
		<span class="description gq-payments-refunds">
			<?php echo esc_html( VerifiedRefunds::refusal() ); ?>
			<a href="<?php echo esc_url( PaymentsPage::BANQUINHA_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Banquinha', 'gq-payments' ); ?></a>
		</span>
		<?php
	}
}
