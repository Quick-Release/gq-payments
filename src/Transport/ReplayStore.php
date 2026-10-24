<?php
/**
 * Single-use request IDs.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Remembers request IDs for at least twice the signature window.
 */
interface ReplayStore {

	/**
	 * How long a claimed request ID is remembered, in seconds.
	 */
	public const TTL = 2 * Wire::WINDOW;

	/**
	 * Atomically claims a request ID for a key.
	 *
	 * @param string $keyId     Key ID.
	 * @param string $requestId Request ID.
	 * @param int    $now       Epoch seconds.
	 * @return bool True the first time; false if it was already claimed and not yet expired.
	 */
	public function claim( string $keyId, string $requestId, int $now ): bool;
}
