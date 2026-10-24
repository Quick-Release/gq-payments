<?php
/**
 * A malformed Payment State message.
 *
 * @package GetQuick\Payments
 */

declare(strict_types=1);

namespace GetQuick\Payments\Order;

use InvalidArgumentException;

/**
 * Thrown by PaymentState::fromPayload(); the message is the offending field's name.
 */
final class InvalidPaymentState extends InvalidArgumentException {
}
