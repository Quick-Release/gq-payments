# GQ Payments

Payment provider integration for GETQUICK WordPress sites.

Version **0.0.1** is the initial scaffold. It loads the plugin lifecycle entry
point but does not process payments, register gateways or webhooks, store
credentials, or change orders. Provider selection and integration are pending.

Requires WordPress 7.0+ and PHP 8.4.1+, matching the newer GQ plugins.

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
composer check
```

`gq-payments.php` owns the WordPress metadata and bootstrap.
`src/Plugin.php` is the service registration entry point, under the
`GetQuick\Payments` PSR-4 namespace. PHP follows WordPress coding standards
with the same namespaced class conventions as GQ Design.

No frontend build or runtime dependencies are needed for this scaffold.

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
