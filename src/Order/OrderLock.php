<?php
/**
 * Per-order lock shared by the Order Snapshot and Payment State routes.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

/**
 * Serialises changes to one order across requests with a MySQL named lock,
 * which works the same under HPOS and legacy order storage.
 */
final class OrderLock {

	/**
	 * Seconds to wait for another request on the same order to finish.
	 */
	private const TIMEOUT = 10;

	/**
	 * Runs $critical while holding the order's lock.
	 *
	 * @template T
	 * @param int           $orderId  Order ID.
	 * @param callable(): T $critical Work that must not interleave with other work on the order.
	 * @return T|null Null when the lock was not acquired within the timeout; $critical did not run.
	 */
	public static function run( int $orderId, callable $critical ): mixed {
		if ( ! self::lock( $orderId ) ) {
			return null;
		}
		try {
			return $critical();
		} finally {
			self::unlock( $orderId );
		}
	}

	/**
	 * Acquires the order's lock.
	 *
	 * @param int $orderId Order ID.
	 */
	private static function lock( int $orderId ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::name( $orderId ), self::TIMEOUT ) );
	}

	/**
	 * Releases the order's lock.
	 *
	 * @param int $orderId Order ID.
	 */
	private static function unlock( int $orderId ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::name( $orderId ) ) );
	}

	/**
	 * Lock name, unique per database prefix and order (MySQL allows 64 characters).
	 *
	 * @param int $orderId Order ID.
	 */
	private static function name( int $orderId ): string {
		global $wpdb;
		return substr( 'gq_payments_order_' . md5( DB_NAME . $wpdb->prefix ) . '_' . $orderId, 0, 64 );
	}
}
