<?php
/**
 * Plugin lifecycle entry point.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

/**
 * Owns registration of payment provider integrations.
 */
final class Plugin {

	/**
	 * Registers plugin services when WordPress has loaded its plugins.
	 */
	public function boot(): void {
		// Provider services will be registered here once the integration is defined.
	}
}
