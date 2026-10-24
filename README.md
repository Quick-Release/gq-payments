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
npm run test:e2e              # site lifecycle and HTTP surface (node --test)
npm run test:e2e:multisite
npx playwright install chromium
npm run test:browser          # admin screen (Playwright)
npm run env:destroy && npm run env:destroy:multisite
```

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
