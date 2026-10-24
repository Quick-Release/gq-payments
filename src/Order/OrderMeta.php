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

	/** Unix time of Payment Start (the first Order Snapshot taken while Pending payment); locks the order. */
	public const PAYMENT_STARTED_AT = '_gq_payments_payment_started_at';

	/** Snapshot Revision at Payment Start. */
	public const SNAPSHOT_REVISION = '_gq_payments_snapshot_revision';

	/** Order total (decimal string) at Payment Start: the amount the Payment Service collects. */
	public const SNAPSHOT_TOTAL = '_gq_payments_snapshot_total';

	/** Version of the last applied Payment State. */
	public const STATE_VERSION = '_gq_payments_state_version';

	/** The Payment Service's opaque attempt ID from the last applied Payment State that had one. */
	public const ATTEMPT_ID = '_gq_payments_attempt_id';

	/** Cumulative verified refunded total (decimal string) from the last applied Payment State. */
	public const REFUNDED_TOTAL = '_gq_payments_refunded_total';

	/** "yes" once a Late Payment was received: money arrived after cancellation; review in Banquinha. */
	public const LATE_PAYMENT = '_gq_payments_late_payment';
}
