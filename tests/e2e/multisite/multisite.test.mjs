// Seam A: GQ Payments on a multisite network is single-site only.
// Needs the multisite environment: npx wp-env --config tests/e2e/multisite/.wp-env.json start
import assert from 'node:assert/strict';
import { before, beforeEach, describe, test } from 'node:test';
import { multisite as site } from '../lib/site.mjs';

const pluginFile = 'gq-payments/gq-payments.php';

async function reset() {
  await site.wp('eval', `
    $network = (array) get_site_option( 'active_sitewide_plugins', [] );
    unset( $network['${pluginFile}'] );
    update_site_option( 'active_sitewide_plugins', $network );`);
  await site.wp('plugin', 'deactivate', 'gq-payments');
}

before(() => site.wpOk('plugin', 'activate', 'woocommerce', 'getquick-ecommerce'));
beforeEach(reset);

describe('on multisite', () => {
  test('network activation is refused', async () => {
    const result = await site.wp('plugin', 'activate', 'gq-payments', '--network');

    assert.equal(result.ok, false, result.stdout);
    assert.match(result.stderr, /single sites only/);
    const network = await site.wpOk('plugin', 'list', '--status=active-network', '--field=name');
    assert.doesNotMatch(network, /gq-payments/);
  });

  test('per-site activation works and registers the screen', async () => {
    await site.wpOk('plugin', 'activate', 'gq-payments');

    const get = await site.loginAs();
    const page = await get('/wp-admin/admin.php?page=gq-payments');
    assert.equal(page.status, 200);
    assert.match(page.html, /<h1[^>]*>Payments<\/h1>/);
  });

  test('a plugin made network-active anyway does not boot and explains why', async () => {
    await site.wpOk('eval', `
      $network = (array) get_site_option( 'active_sitewide_plugins', [] );
      $network['${pluginFile}'] = time();
      update_site_option( 'active_sitewide_plugins', $network );`);

    const get = await site.loginAs();
    const dashboard = await get('/wp-admin/');
    assert.match(
      dashboard.html,
      /GQ Payments is inactive because these requirements are not met: it must be activated per site, not network-wide\./
    );
    assert.doesNotMatch(dashboard.html, /page=gq-payments/);
  });
});
