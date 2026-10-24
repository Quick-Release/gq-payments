=== GQ Payments ===
Tags: payments, getquick, woocommerce
Requires at least: 7.0
Requires PHP: 8.4.1
Requires Plugins: woocommerce, getquick-ecommerce
WC requires at least: 10.9
WC tested up to: 11.2
Stable tag: 0.0.1
License: Proprietary

Connects WooCommerce checkout to GETQUICK's Payment Service.

== Description ==

GQ Payments lets a GETQUICK WooCommerce store collect payment through
GETQUICK's private Payment Service. WordPress never holds payment credentials,
never decides whether a payment happened and never moves money.

* Connect the site on GETQUICK > Payments with a Connection Request; an
  Operator approves its Site Enrollment. The screen shows connection health,
  the environment and the Eligible Methods, which can be switched on or off.
* Shoppers pay through the headless storefront's Store API checkout with a
  hidden gateway. From Payment Start the order is locked and its stock held.
* Signed Payment State from the Payment Service completes paid orders,
  cancels expired ones, flags Late Payments for review and records refunds
  made in Banquinha.
* All traffic with the Payment Service is signed with Ed25519.

Requirements: WordPress 7.0+, PHP 8.4.1+, WooCommerce 10.9+ and GQ eCommerce.
Single sites only (network activation is refused). Works with HPOS and legacy
order storage.

== Installation ==

1. Upload the release ZIP through Plugins > Add New > Upload Plugin.
2. Activate GQ Payments.
3. Optionally define GQ_PAYMENTS_SITE_KEY in wp-config.php or the server
   environment to keep the Site Key out of the database.
4. Open GETQUICK > Payments (or Settings > Payments) and create a Connection
   Request.

Composer/Bedrock installations can require getquick/gq-payments instead.

== Changelog ==

= Unreleased =
* Connection Request, connection health and Eligible Methods on GETQUICK > Payments.
* Site Key custody in GQ_PAYMENTS_SITE_KEY (with GQ_PAYMENTS_SITE_KEY_NEXT for replacement) or the database.
* Hidden gq_payments gateway for the Store API checkout.
* Signed Order Snapshot, Payment Start lock and stock hold.
* Signed, versioned Payment State: paid, expired, Late Payment and verified refunds.
* Ed25519 signing in both directions with replay protection.
* Requirements gate, HPOS compatibility, single-site guard and uninstall cleanup.

= 0.0.1 =
* Initial plugin scaffold, Composer packaging and coding standards checks.
