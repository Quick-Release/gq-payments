<?php
/**
 * Runtime requirements checked before the plugin boots.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

/**
 * Decides whether the site meets GQ Payments' platform requirements.
 *
 * WordPress enforces the `Requires Plugins` header at activation. This check
 * covers what the header cannot: a too-old WooCommerce, a dependency
 * deactivated afterwards, and network activation on multisite.
 */
final class Requirements {

	public const MINIMUM_WOOCOMMERCE = '10.9';

	/**
	 * Lists the unmet requirements for the given environment.
	 *
	 * @param string|null $woocommerceVersion Active WooCommerce version, or null when WooCommerce is not loaded.
	 * @param bool        $networkActive      Whether the plugin is network-activated on a multisite network.
	 * @return list<string> Requirement identifiers: 'woocommerce', 'single-site'.
	 */
	public static function failures( ?string $woocommerceVersion, bool $networkActive ): array {
		$failures = [];

		if ( null === $woocommerceVersion || version_compare( $woocommerceVersion, self::MINIMUM_WOOCOMMERCE, '<' ) ) {
			$failures[] = 'woocommerce';
		}

		if ( $networkActive ) {
			$failures[] = 'single-site';
		}

		return $failures;
	}

	/**
	 * Lists the unmet requirements for the running site.
	 *
	 * @return list<string>
	 */
	public function currentFailures(): array {
		return self::failures(
			defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
			self::isNetworkActive()
		);
	}

	/**
	 * Explains to administrators why GQ Payments is inactive.
	 *
	 * @param string[] $failures Requirement identifiers from failures().
	 */
	public function registerAdminNotice( array $failures ): void {
		$messages = [
			/* translators: %s: minimum WooCommerce version. */
			'woocommerce' => sprintf( __( 'WooCommerce %s or later must be active', 'gq-payments' ), self::MINIMUM_WOOCOMMERCE ),
			'single-site' => __( 'it must be activated per site, not network-wide', 'gq-payments' ),
		];

		add_action(
			'admin_notices',
			static function () use ( $failures, $messages ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				$reasons = array_map( static fn( string $failure ): string => $messages[ $failure ] ?? $failure, $failures );

				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: %s: list of unmet requirements. */
						__( 'GQ Payments is inactive because these requirements are not met: %s.', 'gq-payments' ),
						implode( '; ', $reasons )
					)
				);
				echo '</p></div>';
			}
		);
	}

	/**
	 * Whether the plugin is network-activated, which GQ Payments does not support.
	 */
	private static function isNetworkActive(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$plugins = (array) get_site_option( 'active_sitewide_plugins', [] );

		return isset( $plugins[ plugin_basename( GETQUICK_PAYMENTS_PLUGIN_FILE ) ] );
	}
}
