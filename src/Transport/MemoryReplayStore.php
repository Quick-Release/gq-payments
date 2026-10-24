<?php
/**
 * In-process replay store.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * Replay store for one process (tests, or a single long-lived worker).
 */
final class MemoryReplayStore implements ReplayStore {

	/**
	 * Expiry times by key ID and request ID.
	 *
	 * @var array<string, int>
	 */
	private array $seen = [];

	/**
	 * {@inheritDoc}
	 *
	 * @param string $keyId     Key ID.
	 * @param string $requestId Request ID.
	 * @param int    $now       Epoch seconds.
	 */
	public function claim( string $keyId, string $requestId, int $now ): bool {
		$this->seen = array_filter( $this->seen, static fn( int $expires ): bool => $expires >= $now );
		$id         = $keyId . "\n" . $requestId;
		if ( isset( $this->seen[ $id ] ) ) {
			return false;
		}
		$this->seen[ $id ] = $now + self::TTL;
		return true;
	}
}
