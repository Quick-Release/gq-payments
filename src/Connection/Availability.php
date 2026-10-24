<?php
/**
 * What checkout may offer, as far as the connection is concerned.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Connection;

/**
 * Read-only queries for the gateway. Answers come from the briefly cached
 * status the Payment Service reported; obtain an instance with Connection::fromWordPress().
 */
interface Availability {

	/**
	 * Whether the site's Site Enrollment is connected. False when it was never
	 * approved, is awaiting approval, or was disconnected or revoked. While the
	 * service is unreachable, the last reported answer stands.
	 */
	public function isConnected(): bool;

	/**
	 * Whether checkout may offer this method now: the site is connected and the
	 * Payment Service reports the method eligible and switched on.
	 *
	 * @param string $methodId Method ID, e.g. 'mbway'.
	 */
	public function isMethodEligible( string $methodId ): bool;

	/**
	 * IDs of every method isMethodEligible() accepts, in the service's order.
	 *
	 * @return list<string>
	 */
	public function eligibleMethodIds(): array;
}
