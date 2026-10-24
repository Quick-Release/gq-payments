<?php
/**
 * Default availability: the enrollment option, adjustable through filters.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

use GetQuick\Payments\Transport\Transport;

/**
 * Connected when the site has a Site ID; every known method is eligible while connected.
 *
 * Filters:
 * - gq_payments_site_connected( bool $connected )
 * - gq_payments_method_eligible( bool $eligible, string $method )
 */
final class EnrollmentAvailability implements Availability {

	/**
	 * Whether the site has an active Site Enrollment.
	 */
	public function isConnected(): bool {
		return (bool) apply_filters( 'gq_payments_site_connected', '' !== (string) get_option( Transport::SITE_ID_OPTION, '' ) );
	}

	/**
	 * Whether the Payment Service currently allows this Storefront to offer the method.
	 *
	 * @param string $method Method ID, e.g. "mbway".
	 */
	public function isMethodEligible( string $method ): bool {
		return (bool) apply_filters( 'gq_payments_method_eligible', $this->isConnected() && Methods::isKnown( $method ), $method );
	}
}
