<?php
/**
 * Deployment-level settings that no admin option can change.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

/**
 * Service URL and Site Key, each read as constant, then environment variable.
 * Service Keys are constant-only; see Transport\ServiceKeys.
 */
final class Configuration {

	/**
	 * Release default for the Payment Service URL.
	 *
	 * PLACEHOLDER: empty until the production Payment Service address is fixed.
	 * While empty, outbound calls fail as unreachable unless GQ_PAYMENTS_SERVICE_URL is set.
	 */
	public const DEFAULT_SERVICE_URL = '';

	/**
	 * Payment Service base URL, without a trailing slash.
	 */
	public static function serviceUrl(): string {
		$url = Environment::value( 'GQ_PAYMENTS_SERVICE_URL' );
		return rtrim( '' !== $url ? $url : self::DEFAULT_SERVICE_URL, '/' );
	}

	/**
	 * Site Key (base64) from GQ_PAYMENTS_SITE_KEY, or '' when it is not configured.
	 */
	public static function siteKey(): string {
		return Environment::value( 'GQ_PAYMENTS_SITE_KEY' );
	}
}
