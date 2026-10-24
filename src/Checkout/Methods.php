<?php
/**
 * Payment methods the gateway can carry.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Checkout;

/**
 * How a Store API checkout names its method (payment_data key "gq_method"), and method titles.
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
	 * The method's brand name for the order's payment method title, or null when there is none.
	 *
	 * @param string $method Method ID.
	 */
	public static function title( string $method ): ?string {
		return self::TITLES[ $method ] ?? null;
	}
}
