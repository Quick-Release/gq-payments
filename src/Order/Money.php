<?php
/**
 * Money amounts in the store's price precision.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

/**
 * Converts between decimal strings and integer minor units, so totals are compared and
 * subtracted exactly, and formats amounts for order notes.
 *
 * Pure: minorUnits() and decimal(). The rest use WooCommerce's price decimals.
 */
final class Money {

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

	/**
	 * A WooCommerce amount (order total, refunded total, stored meta) in minor units,
	 * rounded to the store's price decimals.
	 *
	 * @param string|float $amount Amount.
	 */
	public static function toMinor( string|float $amount ): int {
		$decimals = wc_get_price_decimals();
		return self::minorUnits( (string) wc_format_decimal( $amount, $decimals ), $decimals );
	}

	/**
	 * Minor units as a decimal string in the store's price decimals, e.g. "20.00".
	 *
	 * @param int $minor Minor units.
	 */
	public static function fromMinor( int $minor ): string {
		return self::decimal( $minor, wc_get_price_decimals() );
	}

	/**
	 * E.g. "20.00 EUR": plain text for order notes, independent of the store's price format.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency code.
	 */
	public static function format( int $minor, string $currency ): string {
		return self::fromMinor( $minor ) . ' ' . $currency;
	}
}
