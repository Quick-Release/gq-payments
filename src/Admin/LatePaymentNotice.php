<?php
/**
 * The Late Payment notice on the order screen.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use GetQuick\Payments\Checkout\Gateway;
use GetQuick\Payments\Order\OrderMeta;

/**
 * Warns on a gq_payments order's edit screen (HPOS or legacy) when a Late Payment was received.
 */
final class LatePaymentNotice {

	/**
	 * Hooks into WordPress admin.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Prints the notice on the edit screen of a flagged order.
	 */
	public function render(): void {
		if ( ! class_exists( OrderUtil::class ) || ! OrderUtil::is_order_edit_screen() ) {
			return;
		}
		$order = wc_get_order( self::orderId() );
		if ( ! Gateway::owns( $order )
			|| 'yes' !== $order->get_meta( OrderMeta::LATE_PAYMENT )
		) {
			return;
		}
		?>
		<div class="notice notice-error gq-payments-late-payment">
			<p>
				<strong><?php esc_html_e( 'Late Payment:', 'gq-payments' ); ?></strong>
				<?php esc_html_e( 'payment for this order was received after it was cancelled or its stock was released. Do not fulfil it before reviewing the payment in Banquinha, where you can decide to fulfil or refund it.', 'gq-payments' ); ?>
				<a href="<?php echo esc_url( PaymentsPage::BANQUINHA_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Banquinha', 'gq-payments' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * The order being edited: ?id= under HPOS, ?post= under legacy storage.
	 */
	private static function orderId(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$param = OrderUtil::custom_orders_table_usage_is_enabled() ? 'id' : 'post';
		$id    = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? absint( wp_unslash( $_GET[ $param ] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $id;
	}
}
