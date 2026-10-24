<?php
/**
 * What the Order Snapshot and Payment State routes share.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

/**
 * The per-order REST namespace and its entry on gq-config's restricted-REST allow-list.
 */
final class OrderRoutes {

	public const NAMESPACE = 'gq-payments/v1';

	/**
	 * Lets the Payment Service's signed (anonymous) $method requests through gq-config's
	 * restricted REST mode. gq-config matches exact routes or "/prefix/*" only, so per-order
	 * routes share the "/gq-payments/v1/orders/*" entry; Transport::authorize still guards
	 * every route under it.
	 *
	 * @param mixed  $routes Route or "/prefix/*" => allowed methods.
	 * @param string $method HTTP method to add to the entry.
	 * @return array<string, string[]>
	 */
	public static function allowAnonymous( mixed $routes, string $method ): array {
		$routes             = (array) $routes;
		$pattern            = '/' . self::NAMESPACE . '/orders/*';
		$routes[ $pattern ] = array_values( array_unique( array_merge( (array) ( $routes[ $pattern ] ?? [] ), [ $method ] ) ) );
		return $routes;
	}
}
