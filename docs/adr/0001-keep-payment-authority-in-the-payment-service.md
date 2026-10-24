# Keep payment authority in the Payment Service

This plugin is a thin adapter. It provides site configuration, a hidden WooCommerce gateway, Order Snapshots and Payment State mirroring. It holds no provider credentials, calls no payment provider and contains no rules about how money is allocated. Those live in GETQUICK's private Payment Service, so a compromised WordPress site cannot move or redirect funds, and the public code exposes no commercial rules. WooCommerce stays authoritative for order totals, tax, shipping and stock.

## Consequences

- WordPress never marks an order paid by itself. Paid, expired and refunded states come only from verified Payment Service messages. WooCommerce gateway refunds are not supported, and manual WooCommerce refunds are blocked on these orders, because refunds are requested in Banquinha.
- An administrator can still change an order status by hand. The plugin records where each status came from instead of trying to block administrators, and the Payment Service never treats a WooCommerce status as proof of payment.
- This plugin, not GQ eCommerce, owns the payment gateway contract.
