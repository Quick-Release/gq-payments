<?php
/**
 * The GETQUICK → Payments admin screen.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Admin;

use GetQuick\Payments\Connection\Connection;

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
	 * Banquinha, where payments are administered. A plain link: no credentials,
	 * no site or user identity, and no referrer.
	 */
	public const BANQUINHA_URL = 'https://my.banquinha.pt/';

	/** Admin-post actions; each checks the capability and its own nonce. */
	private const ACTIONS = [
		'gq_payments_connection_request' => 'handleConnectionRequest',
		'gq_payments_refresh_status'     => 'handleRefreshStatus',
		'gq_payments_set_method'         => 'handleSetMethod',
	];

	/** Query argument carrying a notice key (never data). */
	private const NOTICE_ARG = 'gq_payments_notice';

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
		// After gq-config's menu (10), GETQUICK Design's entries (20, 21) and GETQUICK Support (22); see addPage() for the position.
		add_action( 'admin_menu', [ $this, 'addPage' ], 23 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
		foreach ( self::ACTIONS as $action => $handler ) {
			add_action( "admin_post_{$action}", [ $this, $handler ] );
		}
	}

	/**
	 * Adds the screen under GETQUICK, or under Settings when that menu is absent.
	 */
	public function addPage(): void {
		global $admin_page_hooks;

		$title = __( 'Payments', 'gq-payments' );

		if ( isset( $admin_page_hooks[ self::GETQUICK_MENU ] ) ) {
			// Position 3: after GETQUICK Design's Design (0) and Elements (1) and GETQUICK Support (2).
			$hook = add_submenu_page( self::GETQUICK_MENU, $title, $title, self::CAPABILITY, self::SLUG, [ $this, 'render' ], 3 );
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
	 * Creates a Connection Request (or a replacement one).
	 */
	public function handleConnectionRequest(): void {
		$this->authorize( 'gq_payments_connection_request' );
		$this->redirect( Connection::fromWordPress()->request() );
	}

	/**
	 * Asks the Payment Service for the connection status now.
	 */
	public function handleRefreshStatus(): void {
		$this->authorize( 'gq_payments_refresh_status' );
		Connection::fromWordPress()->refresh();
		$this->redirect( 'refreshed' );
	}

	/**
	 * Switches an Eligible Method on or off through the Payment Service.
	 */
	public function handleSetMethod(): void {
		$this->authorize( 'gq_payments_set_method' );
		// Nonce checked in authorize().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$method  = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->redirect( '' === $method ? Connection::SERVICE_ERROR : Connection::fromWordPress()->setMethodEnabled( $method, $enabled ) );
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		$connection = Connection::fromWordPress();
		$keys       = $connection->keys();
		$status     = $connection->status();
		$active     = $keys->active();
		$pending    = $keys->pending();
		$shown      = $pending ?? $active;
		$health     = self::healthText( $status['health'] );
		?>
		<div class="wrap gq-payments">
			<h1><?php esc_html_e( 'Payments', 'gq-payments' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<?php if ( ! $keys->managed() ) : ?>
				<div class="notice notice-warning inline gq-payments-key-warning">
					<p><?php esc_html_e( 'The Site Key is stored in the WordPress database, which is the weaker choice: it is included in database exports and backups. Define GQ_PAYMENTS_SITE_KEY in wp-config.php or the server environment to keep it out of the database.', 'gq-payments' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( $keys->enrolledKeyMissing() ) : ?>
				<div class="notice notice-error inline gq-payments-key-missing">
					<p><?php esc_html_e( 'The Site Key enrolled with GETQUICK is no longer configured, so this site cannot sign as its Site Enrollment. Restore it in GQ_PAYMENTS_SITE_KEY, or create a Connection Request for the configured key. Checkout cannot take payment until an Operator approves it.', 'gq-payments' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="gq-payments-card" aria-labelledby="gq-payments-connection">
				<h2 id="gq-payments-connection"><?php esc_html_e( 'Connection', 'gq-payments' ); ?></h2>
				<p class="gq-payments-status">
					<span class="gq-payments-status__badge gq-payments-status__badge--<?php echo esc_attr( $status['health'] ); ?>"><?php echo esc_html( $health[0] ); ?></span>
				</p>
				<p class="description"><?php echo esc_html( $health[1] ); ?></p>
				<?php if ( '' !== $status['environment'] ) : ?>
					<p class="gq-payments-environment">
						<?php
						/* translators: %s: Sandbox or Production. */
						echo esc_html( sprintf( __( 'Environment: %s (set by GETQUICK)', 'gq-payments' ), 'production' === $status['environment'] ? __( 'Production', 'gq-payments' ) : __( 'Sandbox', 'gq-payments' ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php $this->renderAction( 'gq_payments_refresh_status', __( 'Refresh status', 'gq-payments' ) ); ?>
			</section>

			<section class="gq-payments-card" aria-labelledby="gq-payments-connection-request">
				<h2 id="gq-payments-connection-request"><?php esc_html_e( 'Connection Request', 'gq-payments' ); ?></h2>
				<?php if ( null !== $shown ) : ?>
					<?php if ( null !== $pending && null !== $active ) : ?>
						<p><?php esc_html_e( 'Replacement awaiting approval. The current Site Key stays in use until an Operator approves the new one.', 'gq-payments' ); ?></p>
					<?php endif; ?>
					<?php if ( $keys->managed() ) : ?>
						<p class="description"><?php esc_html_e( 'To replace a Site Key set in GQ_PAYMENTS_SITE_KEY, set the new key in GQ_PAYMENTS_SITE_KEY_NEXT and create a replacement Connection Request. Once an Operator approves it, the new key is used; you can then move it to GQ_PAYMENTS_SITE_KEY and remove GQ_PAYMENTS_SITE_KEY_NEXT.', 'gq-payments' ); ?></p>
					<?php endif; ?>
					<p><?php esc_html_e( 'Send these details to GETQUICK. An Operator checks the fingerprint before approving the Site Enrollment.', 'gq-payments' ); ?></p>
					<dl class="gq-payments-request">
						<dt><?php esc_html_e( 'Site URL', 'gq-payments' ); ?></dt>
						<dd><code><?php echo esc_html( home_url() ); ?></code></dd>
						<dt><?php esc_html_e( 'Public key', 'gq-payments' ); ?></dt>
						<dd><code><?php echo esc_html( $shown->publicKey ); ?></code></dd>
						<dt><?php esc_html_e( 'Fingerprint', 'gq-payments' ); ?></dt>
						<dd><code><?php echo esc_html( $shown->fingerprint() ); ?></code></dd>
					</dl>
				<?php else : ?>
					<p><?php esc_html_e( 'Create a Connection Request to connect this site to the GETQUICK Payment Service.', 'gq-payments' ); ?></p>
				<?php endif; ?>
				<?php $this->renderAction( 'gq_payments_connection_request', null === $active ? __( 'Create Connection Request', 'gq-payments' ) : __( 'Create replacement Connection Request', 'gq-payments' ) ); ?>
			</section>

			<section class="gq-payments-card" aria-labelledby="gq-payments-methods">
				<h2 id="gq-payments-methods"><?php esc_html_e( 'Eligible Methods', 'gq-payments' ); ?></h2>
				<p class="description"><?php esc_html_e( 'GETQUICK decides which methods this Storefront may offer. Switching a method off stops new payments with it; payments already started are still recorded.', 'gq-payments' ); ?></p>
				<?php if ( [] === $status['methods'] ) : ?>
					<p><?php esc_html_e( 'No payment methods are eligible yet.', 'gq-payments' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gq-payments-methods">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Method', 'gq-payments' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'gq-payments' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'gq-payments' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $status['methods'] as $method ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $method['label'] ); ?></th>
									<?php if ( 'eligible' !== $method['status'] ) : ?>
										<td><?php esc_html_e( 'Unavailable', 'gq-payments' ); ?></td>
										<td></td>
									<?php else : ?>
										<td><?php echo esc_html( $method['enabled'] ? __( 'On', 'gq-payments' ) : __( 'Off', 'gq-payments' ) ); ?></td>
										<td>
											<?php
											$this->renderAction(
												'gq_payments_set_method',
												$method['enabled']
													/* translators: %s: payment method name. */
													? sprintf( __( 'Turn off %s', 'gq-payments' ), $method['label'] )
													/* translators: %s: payment method name. */
													: sprintf( __( 'Turn on %s', 'gq-payments' ), $method['label'] ),
												[
													'method'  => $method['id'],
													'enabled' => $method['enabled'] ? '0' : '1',
												]
											);
											?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<p class="gq-payments-banquinha">
				<a href="<?php echo esc_url( self::BANQUINHA_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage payments and refunds in Banquinha', 'gq-payments' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Label and masked explanation for a health state.
	 *
	 * @param string $health Connection health.
	 * @return array{0: string, 1: string}
	 */
	private static function healthText( string $health ): array {
		return match ( $health ) {
			Connection::CONNECTED         => [ __( 'Connected', 'gq-payments' ), __( 'An Operator approved this site\'s Site Enrollment. Checkout can take payment with the methods switched on below.', 'gq-payments' ) ],
			Connection::AWAITING_APPROVAL => [ __( 'Awaiting approval', 'gq-payments' ), __( 'An Operator has not approved the Site Enrollment for this Connection Request yet. Checkout cannot take payment until one does.', 'gq-payments' ) ],
			Connection::DISCONNECTED      => [ __( 'Disconnected', 'gq-payments' ), __( 'GETQUICK does not currently accept this site. New checkouts cannot take payment; payments already started are still recorded. Contact GETQUICK or create a new Connection Request.', 'gq-payments' ) ],
			Connection::UNREACHABLE       => [ __( 'Unreachable', 'gq-payments' ), __( 'The Payment Service could not be reached or answered with an error. The status below is the last one it reported.', 'gq-payments' ) ],
			default                       => [ __( 'Not connected', 'gq-payments' ), __( 'This site has not sent a Connection Request yet.', 'gq-payments' ) ],
		};
	}

	/**
	 * Shows the fixed message for the notice key in the URL, if any.
	 */
	private function renderNotice(): void {
		// A notice key only selects one of the fixed messages below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		$messages = [
			Connection::REQUESTED        => [ 'success', __( 'Connection Request sent. Send the fingerprint below to GETQUICK for approval.', 'gq-payments' ) ],
			Connection::ALREADY_ENROLLED => [ 'warning', __( 'This Site Key is already enrolled. To replace it, set GQ_PAYMENTS_SITE_KEY_NEXT to a new key and create the replacement Connection Request again. The current key stays in use until an Operator approves the new one.', 'gq-payments' ) ],
			Connection::INVALID_SITE_KEY => [ 'error', __( 'GQ_PAYMENTS_SITE_KEY or GQ_PAYMENTS_SITE_KEY_NEXT is not a valid Ed25519 key.', 'gq-payments' ) ],
			Connection::UNREACHABLE      => [ 'error', __( 'The Payment Service could not be reached. Try again later.', 'gq-payments' ) ],
			Connection::SERVICE_ERROR    => [ 'error', __( 'The Payment Service refused the request.', 'gq-payments' ) ],
			Connection::NOT_CONNECTED    => [ 'error', __( 'Connect this site before changing payment methods.', 'gq-payments' ) ],
			Connection::SAVED            => [ 'success', __( 'Payment method updated.', 'gq-payments' ) ],
			'refreshed'                  => [ 'info', __( 'Connection status refreshed.', 'gq-payments' ) ],
		];
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	/**
	 * A one-button admin-post form with its nonce.
	 *
	 * @param string                $action Admin-post action.
	 * @param string                $label  Button label.
	 * @param array<string, string> $fields Extra hidden fields.
	 */
	private function renderAction( string $action, string $label, array $fields = [] ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gq-payments-action">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Refuses users without the capability (403) and requests without the action's nonce.
	 *
	 * @param string $action Admin-post action, also the nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payments.', 'gq-payments' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the screen with a notice key.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
