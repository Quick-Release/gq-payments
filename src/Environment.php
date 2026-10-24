<?php
/**
 * Reads deployment configuration.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments;

/**
 * Same lookup as GQ eCommerce's Environment reader.
 */
final class Environment {

	/**
	 * A constant (wp-config/Bedrock) wins over the process environment.
	 *
	 * @param string $name Constant / environment variable name.
	 * @return string Trimmed value, or '' when neither is set.
	 */
	public static function value( string $name ): string {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}
		$value = getenv( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}
}
