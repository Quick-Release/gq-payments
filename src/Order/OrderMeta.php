<?php
/**
 * Order meta keys GQ Payments writes (through the WooCommerce CRUD API only).
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

/**
 * Meta keys on gq_payments orders.
 */
final class OrderMeta {

	/** Method ID the shopper chose at checkout, e.g. "mbway". */
	public const METHOD = '_gq_payments_method';
}
