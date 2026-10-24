<?php
/**
 * What the Payment Service answered.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Transport;

/**
 * An HTTP reply, or status 0 when there was no usable reply (not configured,
 * network failure, or a response over the size limit). Responses are not signed
 * in v1: treat them as data from a TLS-authenticated host, never as payment evidence.
 */
final class ServiceResponse {

	/**
	 * Builds a response.
	 *
	 * @param int    $status HTTP status; 0 when unreachable.
	 * @param string $body   Raw body.
	 */
	public function __construct( public readonly int $status, public readonly string $body = '' ) {}

	/**
	 * No usable reply.
	 */
	public static function unreachable(): self {
		return new self( 0 );
	}

	/**
	 * Whether the service answered 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Body decoded as a JSON object/array, or null.
	 *
	 * @return array<mixed>|null
	 */
	public function json(): ?array {
		$data = json_decode( $this->body, true );
		return is_array( $data ) ? $data : null;
	}
}
