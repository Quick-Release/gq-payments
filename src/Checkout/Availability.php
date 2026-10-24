<?php
/**
 * What the gateway needs to know about the connection before offering a method.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

/**
 * Connection state and Eligible Methods, as seen by checkout.
 */
interface Availability {

	/**
	 * Whether the site has an active Site Enrollment.
	 */
	public function isConnected(): bool;

	/**
	 * Whether the Payment Service currently allows this Storefront to offer the method.
	 *
	 * @param string $method Method ID, e.g. "mbway".
	 */
	public function isMethodEligible( string $method ): bool;
}
