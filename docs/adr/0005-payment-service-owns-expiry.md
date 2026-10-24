# The Payment Service owns payment expiry; stock is held from Payment Start

Some payment methods give customers days to pay, much longer than WooCommerce's "Hold stock" window. The plugin therefore excludes its orders from WooCommerce's unpaid-order cancellation and runs no timer of its own. It reduces stock at Payment Start and cancels the order, which restores stock, only when the Payment Service sends an expired Payment State. The service knows the payment method's real deadline. Running a single timer there avoids a store cancelling an order that the customer then pays.

## Consequences

- If the Payment Service is unreachable, orders stay pending and stock stays held until it recovers. Paying late is recorded as a Late Payment, not lost.
- Orders stay in Pending payment, not On hold, so WooCommerce sends no misleading on-hold emails.

## Amendment (2026-10-24)

The exclusion from WooCommerce's unpaid-order cancellation applies only from Payment Start. Before it, no payment exists and the Payment Service holds no deadline for the order, so excluding it would leave abandoned checkouts pending forever. WooCommerce's usual cancellation therefore still applies to a `gq_payments` order that never reached Payment Start. The snapshot route refuses a cancelled order, so no payment can start on it afterwards.
