<?php
/**
 * One versioned Payment State message from the Payment Service.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

/**
 * The Payment Service's verdict on an order's payment (ADR 0004), parsed and validated.
 *
 * Pure PHP: no WordPress or WooCommerce calls.
 */
final class PaymentState {

	public const PENDING      = 'pending';
	public const PAID         = 'paid';
	public const EXPIRED      = 'expired';
	public const LATE_PAYMENT = 'late_payment';

	public const STATES = [ self::PENDING, self::PAID, self::EXPIRED, self::LATE_PAYMENT ];

	/**
	 * Builds a validated state.
	 *
	 * @param int    $version          Version; only increases.
	 * @param string $state            One of STATES.
	 * @param int    $refundedMinor    Cumulative verified refunded total, in minor units.
	 * @param string $method           Payment method ID, e.g. "mbway".
	 * @param string $attemptId        The service's opaque attempt ID ('' when there is none yet).
	 * @param string $snapshotRevision Snapshot Revision the payment was collected against.
	 */
	private function __construct(
		public readonly int $version,
		public readonly string $state,
		public readonly int $refundedMinor,
		public readonly string $method,
		public readonly string $attemptId,
		public readonly string $snapshotRevision
	) {}

	/**
	 * Parses a decoded JSON body.
	 *
	 * @param mixed $payload  Decoded JSON body.
	 * @param int   $decimals The store's price decimals (refunded_total may have at most this many).
	 * @throws InvalidPaymentState When a field is missing or malformed; the message names the field.
	 */
	public static function fromPayload( mixed $payload, int $decimals ): self {
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidPaymentState( 'body' );
		}

		$version = $payload['version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			throw new InvalidPaymentState( 'version' );
		}

		$state = $payload['state'] ?? null;
		if ( ! in_array( $state, self::STATES, true ) ) {
			throw new InvalidPaymentState( 'state' );
		}

		$refunded = $payload['refunded_total'] ?? null;
		$fraction = $decimals > 0 ? '(?:\.\d{1,' . $decimals . '})?' : '';
		if ( ! is_string( $refunded ) || 1 !== preg_match( '/^\d{1,12}' . $fraction . '$/D', $refunded ) ) {
			throw new InvalidPaymentState( 'refunded_total' );
		}
		$refundedMinor = self::minorUnits( $refunded, $decimals );
		if ( $refundedMinor > 0 && ! in_array( $state, [ self::PAID, self::LATE_PAYMENT ], true ) ) {
			// Only collected money can be refunded.
			throw new InvalidPaymentState( 'refunded_total' );
		}

		$method = $payload['method'] ?? null;
		if ( ! is_string( $method ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/D', $method ) ) {
			throw new InvalidPaymentState( 'method' );
		}

		$attemptId = $payload['attempt_id'] ?? null;
		$attemptId = null === $attemptId ? '' : $attemptId;
		if ( ! is_string( $attemptId ) || 1 !== preg_match( '/^[A-Za-z0-9_.:-]{0,128}$/D', $attemptId ) ) {
			throw new InvalidPaymentState( 'attempt_id' );
		}
		if ( '' === $attemptId && in_array( $state, [ self::PAID, self::LATE_PAYMENT ], true ) ) {
			// A collected payment always has the attempt that collected it.
			throw new InvalidPaymentState( 'attempt_id' );
		}

		$revision = $payload['snapshot_revision'] ?? null;
		if ( ! is_string( $revision ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $revision ) ) {
			throw new InvalidPaymentState( 'snapshot_revision' );
		}

		return new self( $version, $state, $refundedMinor, $method, $attemptId, $revision );
	}

	/**
	 * A decimal string as integer minor units, e.g. "12.3" with 2 decimals => 1230.
	 *
	 * @param string $amount   Non-negative decimal string with at most $decimals decimals.
	 * @param int    $decimals Decimals.
	 */
	public static function minorUnits( string $amount, int $decimals ): int {
		[ $whole, $fraction ] = array_pad( explode( '.', $amount, 2 ), 2, '' );
		return (int) ( $whole . str_pad( $fraction, $decimals, '0' ) );
	}

	/**
	 * Integer minor units as a decimal string, e.g. 1230 with 2 decimals => "12.30".
	 *
	 * @param int $minor    Minor units.
	 * @param int $decimals Decimals.
	 */
	public static function decimal( int $minor, int $decimals ): string {
		if ( $decimals <= 0 ) {
			return (string) $minor;
		}
		$digits = str_pad( (string) $minor, $decimals + 1, '0', STR_PAD_LEFT );
		return substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}
}
