# GQ Payments

GQ Payments is the WordPress-side adapter that lets a GETQUICK WooCommerce store collect payment through GETQUICK's private Payment Service. The store keeps authority over orders and stock; the Payment Service keeps authority over money.

## Parties

**Payment Service**:
GETQUICK's private service that starts payments, verifies payment outcomes and decides each order's Payment State.
_Avoid_: backend, gateway, provider

**Merchant**:
The business that sells through a store and receives its customers' payments.
_Avoid_: seller, client, vendor

**Storefront**:
A Merchant's site that GETQUICK has explicitly approved to collect payments.
_Avoid_: shop, domain, site (when meaning an approved one)

**Operator**:
A GETQUICK staff member who approves Site Enrollments and manages payment configuration outside WordPress.
_Avoid_: admin, support

## Connection

**Site Enrollment**:
The Operator-approved binding between one WordPress site, one Storefront and one environment (sandbox or production).
_Avoid_: registration, activation, onboarding

**Connection Request**:
The site's public identity, which a WordPress administrator hands to GETQUICK so an Operator can approve a Site Enrollment.
_Avoid_: API key request, pairing code

**Site Key**:
The private key with which a WordPress site proves its identity to the Payment Service.
_Avoid_: API key, secret, token

**Service Key**:
The Payment Service's public key, shipped with the plugin, by which the site recognises genuine messages from the Payment Service.
_Avoid_: webhook secret

**Eligible Method**:
A payment method the Payment Service currently allows a Storefront to offer at checkout.
_Avoid_: enabled gateway, available method

## Payment

**Order Snapshot**:
The minimal, store-authoritative financial summary of one order that the Payment Service freezes before asking a customer to pay.
_Avoid_: cart, order payload, totals

**Snapshot Revision**:
The fingerprint identifying which version of an order an Order Snapshot describes.
_Avoid_: order version, hash

**Payment Start**:
The moment the Payment Service accepts an Order Snapshot and begins collecting payment for it; from then on the order is locked.
_Avoid_: checkout, charge, payment creation

**Payment State**:
The Payment Service's current verdict on an order's payment, which the store mirrors and never decides by itself.
_Avoid_: payment status, order status

**Late Payment**:
A verified payment that arrives after its order was cancelled or its stock released; it is recorded for review, never fulfilled automatically.
_Avoid_: overdue payment, orphan payment
