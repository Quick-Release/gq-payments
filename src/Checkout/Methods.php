<?php
/**
 * Payment methods the gateway can carry.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

/**
 * Method IDs sent in the Store API checkout's payment_data (key "gq_method"), with their titles.
 */
final class Methods {

	/** The payment_data key that carries the chosen method. */
	public const PAYMENT_DATA_KEY = 'gq_method';

	/** Method ID => per-order payment method title (brand names, not translated). */
	private const TITLES = [
		'mbway'      => 'MB WAY',
		'multibanco' => 'Multibanco',
	];

	/**
	 * Every method ID the plugin knows.
	 *
	 * @return list<string>
	 */
	public static function ids(): array {
		return array_keys( self::TITLES );
	}

	/**
	 * Whether the plugin knows the method.
	 *
	 * @param string $method Method ID.
	 */
	public static function isKnown( string $method ): bool {
		return isset( self::TITLES[ $method ] );
	}

	/**
	 * The method's title, or null for an unknown method.
	 *
	 * @param string $method Method ID.
	 */
	public static function title( string $method ): ?string {
		return self::TITLES[ $method ] ?? null;
	}
}
