<?php
/**
 * One trusted Payment Service public key.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * A Service Key: its key ID, the environment it signs for and its raw public key.
 */
final class ServiceKey {

	/**
	 * Builds a Service Key.
	 *
	 * @param string $id          Key ID, as sent in GQ-Payments-Key-Id.
	 * @param string $environment 'sandbox' or 'production'.
	 * @param string $publicKey   Raw 32-byte Ed25519 public key.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $environment,
		public readonly string $publicKey
	) {}
}
