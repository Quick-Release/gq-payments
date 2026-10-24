<?php
/**
 * The GETQUICK → Payments admin screen.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Admin;

/**
 * Registers the Payments screen beside the other GETQUICK settings.
 *
 * The screen sits under gq-config's GETQUICK menu when it exists and falls
 * back to Settings → Payments otherwise. Either way it lives at
 * admin.php?page=gq-payments and requires manage_woocommerce.
 */
final class PaymentsPage {

	public const SLUG = 'gq-payments';

	public const CAPABILITY = 'manage_woocommerce';

	/** The gq-config top-level menu. */
	private const GETQUICK_MENU = 'getquick-options';

	/**
	 * Hook suffix of the registered screen, used to scope its assets.
	 *
	 * @var string
	 */
	private string $hookSuffix = '';

	/**
	 * Hooks the screen into the admin menu.
	 */
	public function register(): void {
		// After gq-config's menu (10), GETQUICK Design's entries (20, 21) and GETQUICK Support (22).
		add_action( 'admin_menu', [ $this, 'addPage' ], 23 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
	}

	/**
	 * Adds the screen under GETQUICK, or under Settings when that menu is absent.
	 */
	public function addPage(): void {
		global $admin_page_hooks;

		$title = __( 'Payments', 'gq-payments' );

		if ( isset( $admin_page_hooks[ self::GETQUICK_MENU ] ) ) {
			$hook = add_submenu_page( self::GETQUICK_MENU, $title, $title, self::CAPABILITY, self::SLUG, [ $this, 'render' ] );
		} else {
			$hook = add_options_page( $title, $title, self::CAPABILITY, self::SLUG, [ $this, 'render' ] );
		}

		$this->hookSuffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the screen's stylesheet on this screen only.
	 *
	 * @param string $hookSuffix The current admin screen's hook suffix.
	 */
	public function enqueueAssets( string $hookSuffix ): void {
		if ( '' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix ) {
			return;
		}

		wp_enqueue_style(
			'gq-payments-admin',
			plugins_url( 'assets/admin.css', GETQUICK_PAYMENTS_PLUGIN_FILE ),
			[],
			GETQUICK_PAYMENTS_VERSION
		);
	}

	/**
	 * Renders the screen. The connection slice replaces the status placeholder.
	 */
	public function render(): void {
		?>
		<div class="wrap gq-payments">
			<h1><?php esc_html_e( 'Payments', 'gq-payments' ); ?></h1>
			<section class="gq-payments-card" aria-labelledby="gq-payments-connection">
				<h2 id="gq-payments-connection"><?php esc_html_e( 'Connection', 'gq-payments' ); ?></h2>
				<p class="gq-payments-status">
					<span class="gq-payments-status__badge"><?php esc_html_e( 'Not connected', 'gq-payments' ); ?></span>
				</p>
				<p class="description"><?php esc_html_e( 'Connecting this site to the GETQUICK Payment Service is not available yet.', 'gq-payments' ); ?></p>
			</section>
		</div>
		<?php
	}
}
