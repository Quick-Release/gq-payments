# Mirror versioned Payment State, not events

The Payment Service sends an order's complete current Payment State with a version number that only increases. The state is pending, paid, expired or Late Payment, plus the cumulative verified refunded amount. It does not send a stream of events. The plugin applies only versions newer than the last one it stored and acknowledges repeats as success. Duplicate, reordered and post-restore deliveries therefore converge to the same state without an event log in WordPress.

## Consequences

- A refund is mirrored as a WooCommerce refund for the increase in the cumulative refunded amount only. It has no line items, does not refund through the gateway and does not restock.
- A paid state completes the order through `payment_complete()`, using the service's opaque attempt ID as the transaction ID. A Late Payment never changes the order's status. It only adds a flag and a note.
