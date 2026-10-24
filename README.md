# GQ Payments

Payment provider integration for GETQUICK WordPress sites.

Version **0.0.1** is the initial scaffold. It loads the plugin lifecycle entry
point but does not process payments, register gateways or webhooks, store
credentials, or change orders. Provider selection and integration are pending.

Requires WordPress 7.0+ and PHP 8.4.1+, matching the newer GQ plugins, plus
WooCommerce 10.9+ and GQ eCommerce (`Requires Plugins: woocommerce,
getquick-ecommerce`). GQ Payments is single-site only: network activation is
refused. Its screen is **GETQUICK → Payments** (gq-config's menu), or
**Settings → Payments** when that menu is absent, and needs the
`manage_woocommerce` capability.

Uninstalling deletes the plugin's `gq_payments_*` options and transients and
its WooCommerce gateway settings. Order meta and order notes are kept as
payment history, and uninstall contacts no external service.

## Signed transport and configuration

All traffic between the site and the Payment Service is signed with Ed25519
in both directions (ADR 0002). Each request carries the `GQ-Payments-Key-Id`,
`GQ-Payments-Site-Id`, `GQ-Payments-Timestamp` and `GQ-Payments-Request-Id`
headers, plus an RFC 9530 `Content-Digest` and `GQ-Payments-Signature`. The
signature covers a `gq-payments-v1` canonical string. Requests are accepted
within ±5 minutes, and each request ID only once. Responses to the site's own
calls are not signed in v1. Those calls go over HTTPS only, with redirects off
and a 1 MiB response limit.

| Setting | Source | Notes |
|---|---|---|
| `GQ_PAYMENTS_SERVICE_URL` | constant, then environment variable | Overrides the release default. Never an option. |
| `GQ_PAYMENTS_SITE_KEY` | constant, then environment variable | Base64 Ed25519 secret key (64 bytes) or seed (32 bytes). |
| `GQ_PAYMENTS_SERVICE_PUBLIC_KEYS` | constant only | Array of key ID ⇒ `['environment' => 'sandbox'\|'production', 'public_key' => '<base64 32 bytes>']`. It replaces the shipped Service Keys. |

> **Placeholders:** the real Payment Service keys and address don't exist
> yet. The release ships placeholder current and next Service Keys for each
> environment (`placeholder-*`), and they verify nothing. The default service
> URL is empty, so outbound calls fail as unreachable. Until a release ships
> the real values, set them with the constants above.

The plugin stores seen request IDs in the `{prefix}gq_payments_replay` table,
which uninstall drops.

## Connection

On **GETQUICK → Payments** an administrator creates a **Connection Request**.
The site sends its URL, the Site Key's public key and a short fingerprint
(`SHA256:xxxx:xxxx:xxxx:xxxx`) to the Payment Service, and the screen shows
them so they can be passed to GETQUICK. An Operator approves the Site
Enrollment outside WordPress. A replacement Connection Request creates a
pending key, which replaces the active key only once the service reports the
new enrollment connected. No button deletes the key or disconnects the site.

When `GQ_PAYMENTS_SITE_KEY` is not set, keys are generated into the
`gq_payments_site_keys` option (autoload off), and the screen warns that this
is the weaker choice. When it is set, no private key is written to the
database.

Health (connected, awaiting approval, disconnected or unreachable), the
environment and the **Eligible Methods** come from a signed status call and
are cached for 60 seconds (`gq_payments_connection`). The Payment Service owns
the environment and the method toggles; turning a method on or off goes
through the signed site-configuration call. Error messages are fixed texts
that never repeat what the service said. The Banquinha link carries no
credentials or identity.

| Call (site → service, signed) | Body | Reply |
|---|---|---|
| `POST /v1/site/enrollments` (signed by the new key, empty key ID) | `site_id`, `site_url`, `public_key`, `fingerprint` | `key_id`, `status` |
| `GET /v1/site/status` | — | `status` (`connected`, `awaiting_approval`, `disconnected`, `revoked`), `environment`, `methods` |
| `POST /v1/site/configuration` | `methods: { <id>: bool }` | `methods` |

Each method is `{ id, label, status: 'eligible'|'unavailable', enabled }`.
The gateway asks `GetQuick\Payments\Connection\Connection::fromWordPress()`,
which implements `Connection\Availability`: `isConnected()`,
`isMethodEligible( $id )` (connected, eligible and switched on) and
`eligibleMethodIds()`. While the service is unreachable, the last reported
answer stands. Signed Payment Service requests are verified whatever the
connection state, so existing orders keep receiving Payment State.

## Checkout and Order Snapshot

The `gq_payments` gateway is used only through the Store API (ADR 0003). The
storefront sends `payment_method: "gq_payments"` to `POST /wc/store/v1/checkout`,
with the chosen method in `payment_data` as `{ "key": "gq_method", "value":
"mbway" }`. The gateway:

- leaves the order in Pending payment, titled with the method, and calls no
  external service;
- has no refunds support and no settings;
- is hidden from classic checkout and pay-for-order pages, and registers no
  block checkout integration;
- is available only while the site is connected and at least one method is
  eligible. A checkout with a missing or ineligible method gets a 400.

Availability comes from `Connection\Availability` (see Connection). The
gateway is available while the site is connected and at least one method is
eligible and switched on, and a checkout must name one of those methods.
The order's payment method title is the method's brand name (`MB WAY`,
`Multibanco`), or the gateway's title for other methods.

The Payment Service then fetches
`GET /wp-json/gq-payments/v1/orders/{id}/snapshot?key={order key}`, signed
(`Transport::authorize`). Unknown orders, wrong keys and orders of other
gateways all get the same 404. The body holds totals only, as decimal strings:

```json
{ "order_id": 123, "currency": "EUR", "status": "pending",
  "totals": { "items_net": "18.00", "items_tax": "4.14", "shipping_net": "5.00",
              "shipping_tax": "1.15", "fees_net": "0.00", "fees_tax": "0.00", "total": "28.29" },
  "snapshot_revision": "<sha256 hex of order ID, currency and totals>" }
```

The first snapshot of a Pending payment order marks Payment Start. From then
on:

- the order is no longer editable;
- its stock is reduced once (`wc_maybe_reduce_stock_levels`), and the Store
  API's checkout reservation is released.

`gq_payments` orders are never cancelled by WooCommerce's unpaid-order timer
(ADR 0005). The route is added to gq-config's restricted-REST allow-list as
`/gq-payments/v1/orders/*` (GET).

Order meta (CRUD API only): `_gq_payments_method` (the method chosen at
checkout).

Order meta written at Payment Start: `_gq_payments_payment_started_at` (Unix
time), `_gq_payments_snapshot_revision` and `_gq_payments_snapshot_total` (the
revision and total at Payment Start: the amount the service collects).

## Payment State

The Payment Service sends each order's complete, versioned Payment State
(ADR 0004) to `POST /wp-json/gq-payments/v1/orders/{id}/payment-state`, signed
(`Transport::authorize`, whatever the connection state). The body is JSON:

```json
{ "version": 3, "state": "paid", "refunded_total": "0.00", "method": "mbway",
  "attempt_id": "att_123", "snapshot_revision": "<64 hex>" }
```

| Field | Rule |
|---|---|
| `version` | Integer ≥ 1. Increases with every change. |
| `state` | `pending`, `paid`, `expired` or `late_payment`. |
| `refunded_total` | Cumulative verified refunded amount, a decimal string with at most the store's price decimals. Must be `0` unless the state is `paid` or `late_payment`, never decreases, and never exceeds the amount collected (when the revision is known). |
| `method` | Method ID, `[a-z0-9_-]{1,64}`. |
| `attempt_id` | The service's opaque attempt ID, `[A-Za-z0-9_.:-]{0,128}`; required for `paid` and `late_payment`, otherwise optional (`null` or `""`). |
| `snapshot_revision` | Snapshot Revision the payment was collected against. |

Replies:

- **200** `{ "order_id", "version", "applied" }`. `version` is the order's last
  applied version. A repeated or older version is acknowledged with
  `applied: false` and has no effect, so retries and reordering converge.
- **400** `gq_payments_invalid_payment_state`, with `data.field` naming the
  offending field. Nothing changed; don't retry the same message.
- **401** unsigned, forged, stale or replayed. **404** unknown order or another
  gateway's order. **409** `gq_payments_payment_not_started` (no snapshot was
  taken yet). **503** `gq_payments_busy` (another message for the order held
  its lock for 10 s; retry).

Messages for one order are applied one at a time (a MySQL named lock), and only
when their version is newer than the stored one:

| State | Order Pending payment | Order cancelled | Any other status |
|---|---|---|---|
| `pending` | note only | note only | note only |
| `paid` | `payment_complete( attempt_id )`: Processing or Completed, the usual WooCommerce emails, attempt ID as transaction ID | recorded as a Late Payment | note only, status unchanged |
| `expired` | cancelled; WooCommerce restores the stock held since Payment Start and sends its admin "Cancelled order" email | note only | note only, not cancelled |
| `late_payment` | Late Payment | Late Payment | Late Payment |

- **Late Payment:** the status never changes and `payment_complete()` is never
  called. The order gets the `_gq_payments_late_payment` flag, a note starting
  "Payment received after cancellation — review in Banquinha", and an error
  notice on its edit screen (HPOS and legacy).
- **Revision mismatch:** a `paid` state whose `snapshot_revision` no longer
  matches the order (it was changed after Payment Start, for example through
  the REST API) is still applied, with a note "collected X, current total Y —
  review".
- **Status sources:** every applied Payment State is noted with its version and
  attempt ID. Any other status change on a `gq_payments` order (admin screen,
  REST API, CLI, other plugins) gets a "Status set manually to … — not payment
  evidence" note. The Store API's own draft-to-pending step is not noted.
- **Refunds:** the verified `refunded_total` is validated and stored, but no
  WooCommerce refund is created yet. The `Order\RefundMirror` step of
  `Order\PaymentStateMirror` receives each applied version (after the status
  step, inside the Payment State flag, before the save) and is a no-op
  (`NoRefundMirror`) for now.

The route shares the `/gq-payments/v1/orders/*` entry on gq-config's
restricted-REST allow-list (GET and POST).

Order meta written by Payment State: `_gq_payments_state_version` (last
applied version), `_gq_payments_attempt_id` (last attempt ID received),
`_gq_payments_refunded_total` (last verified refunded total) and
`_gq_payments_late_payment` (`yes`). No provider references, payer details or
raw payloads are stored. All order meta goes through the WooCommerce CRUD API,
so HPOS and legacy storage behave the same, and uninstall keeps it.

## Installation

Download `gq-payments-0.0.1.zip` from the GitHub release, upload it through
**Plugins → Add New → Upload Plugin**, then activate **GQ Payments**.
The standalone ZIP does not require Composer.

For a Composer/Bedrock site, add this repository to the site's Composer
repositories (or its existing GETQUICK registry) and require
`getquick/gq-payments:^0.0.1`. The package installs as `gq-payments`.

## Development

```sh
composer install
composer check   # WordPress coding standards
composer test    # PHPUnit, no WordPress
```

Integration and admin tests run against disposable WordPress sites (Docker and
Node 24 required). WordPress and WooCommerce come from wordpress.org; the
private `getquick-ecommerce` plugin and gq-config menu are replaced by stubs in
`tests/e2e/stubs` and `tests/e2e/mu-plugins`, so no private repository is
needed.

```sh
npm ci
npm run env:start             # single site on http://localhost:8988 (admin/password)
npm run env:start:multisite   # multisite on http://localhost:8990
npm run test:node             # signing vectors on the Node side (no WordPress)
npm run env:storage:hpos      # or env:storage:legacy (WooCommerce order storage)
npm run test:e2e              # site lifecycle and HTTP surface (node --test)
npm run test:e2e:multisite
npx playwright install chromium
npm run test:browser          # admin screen (Playwright)
npm run env:destroy && npm run env:destroy:multisite
```

`tests/fixtures/signing.json` holds **test-only** keys and signing vectors.
PHPUnit (`tests/php`) and the fake Payment Service (`tests/e2e/fake-service`)
both check them, which proves the two sides agree on the wire format.

The fake runs inside the Node test process, on `https://0.0.0.0:9443` with a
test-only certificate. Start it with `startFakeService()`. It gives tests
three tools:

- **Recording:** it verifies and records the site's signed calls in `calls`.
- **Scripted replies:** `respond(method, path, reply)` sets how it answers.
- **Signed calls into the site:** `callSite()`, `signRequest()` and `send()`
  sign requests with the test Service Keys and send them.

The test mu-plugin also records every WooCommerce email sent (ID, order and
recipient) in the `gq_test_emails` option, so tests can assert which emails
went out without a mail catcher.

The test mu-plugin `tests/e2e/mu-plugins/gq-test-env.php` points the site at
`https://host.docker.internal:9443` and defines the test keys. It relaxes the
safe-request checks only for that host. It also registers `gq-test/v1`
fixture routes guarded by `Transport::authorize`.

The gq-config stub, `gq-config-stub.php`, emulates the GETQUICK menu. It also
emulates the restricted-REST gate, which these options toggle:

- `gq_test_anonymous_rest_mode`: `restricted` turns the gate on.
- `gq_test_public_rest_routes`: extra allow-list entries.

CI runs the site suite under legacy and HPOS order storage.

To run a second environment alongside the default one, put the ports in a
git-ignored `.wp-env.override.json`, for example
`{ "port": 8890, "config": { "GQ_TEST_FAKE_SERVICE_PORT": 9444 } }`, and run the
tests with `WP_BASE_URL=http://localhost:8890 GQ_TEST_FAKE_SERVICE_PORT=9444`.

Run the npm scripts from the repository root: wp-env resolves the multisite
config's local paths against the working directory. Recreate the multisite
environment (destroy, then start) after changing its config; restarting it
in place can drop its multisite constants.

`gq-payments.php` owns the WordPress metadata, the requirements gate and the
bootstrap, including a PSR-4 fallback autoloader for standalone installs.
`src/Plugin.php` wires the modules, under the `GetQuick\Payments` PSR-4
namespace. PHP follows WordPress coding standards
with the same namespaced class conventions as GQ Design.

There is no frontend build; Node is used only for tests.

## Releases

Keep the version in `gq-payments.php`, `composer.json` and `readme.txt` aligned,
and update the changelog before tagging a release. Run `composer check`, then
build the distribution from a clean checkout:

```sh
mkdir -p dist/gq-payments
composer archive --format=zip --dir=dist --file=package
unzip -q dist/package.zip -d dist/gq-payments
(cd dist && zip -qr gq-payments-0.0.1.zip gq-payments)
```

Publish the ZIP with the matching `v0.0.1` Git tag. The archive contains only
the runtime files, package metadata, documentation and license.

## License

Proprietary, following the other GQ plugins. Public repository visibility does
not grant a license to redistribute or reuse the code. See [LICENSE](LICENSE).
