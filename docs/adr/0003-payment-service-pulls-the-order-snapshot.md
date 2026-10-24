# The Payment Service pulls the Order Snapshot

The WooCommerce Store API checkout only creates a pending order with `payment_method=gq_payments`. The storefront then asks the Payment Service to start payment for that order. The Payment Service fetches a signed Order Snapshot from this plugin, which requires the order key. The alternative was having `process_payment()` push the order to the service synchronously during checkout. We chose the pull so that a request that moves money never runs inside a PHP checkout request, recovery after a lost response belongs to the service, and payer details (such as a phone number) never pass through WordPress.

## Consequences

- An Order Snapshot carries totals only: item value after discounts excluding VAT, item VAT, shipping, fees, the gross total, currency, status and Snapshot Revision. It has no line items and no customer data.
- After Payment Start the order cannot be edited. A paid message for an older Snapshot Revision is still applied, with a review note, because collected money is always recorded.
- WordPress never shows payment instructions. The storefront shows them.
