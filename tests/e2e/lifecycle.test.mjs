// Seam A: plugin lifecycle on a single site (activation, requirements, HPOS, uninstall).
import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { baseline, singleSite as site } from './lib/site.mjs';

before(() => baseline());
after(() => baseline());

describe('activation', () => {
  for (const dependency of ['woocommerce', 'getquick-ecommerce']) {
    test(`is refused while ${dependency} is inactive`, async () => {
      await site.wpOk('plugin', 'deactivate', 'gq-payments');
      await site.wpOk('plugin', 'deactivate', dependency);

      const result = await site.wp('plugin', 'activate', 'gq-payments');

      assert.equal(result.ok, false, result.stdout);
      assert.equal(await site.wpOk('plugin', 'get', 'gq-payments', '--field=status'), 'inactive');
      await baseline();
    });
  }

  test('succeeds with WooCommerce and GQ eCommerce active', async () => {
    await site.wpOk('plugin', 'deactivate', 'gq-payments');

    await site.wpOk('plugin', 'activate', 'gq-payments');

    assert.equal(await site.wpOk('plugin', 'get', 'gq-payments', '--field=status'), 'active');
  });
});

describe('WooCommerce order storage', () => {
  test('runs under the order storage selected for this run (GQ_TEST_STORAGE)', async (t) => {
    const expected = process.env.GQ_TEST_STORAGE;
    if (!expected) {
      t.skip('GQ_TEST_STORAGE not set');
      return;
    }
    const hpos = await site.wpOk(
      'eval',
      "echo \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';"
    );
    assert.equal(hpos, expected);
  });

  test('GQ Payments is declared compatible with HPOS', async () => {
    const info = await site.wpOk('wc', 'hpos', 'compatibility-info', '--display-filenames');

    const compatible = info.split(/incompatible plugins? found/)[0];
    assert.match(compatible, /gq-payments\/gq-payments\.php/, info);
  });
});

describe('uninstall', () => {
  test('removes plugin options, keeps order payment history and contacts no external service', async () => {
    await site.wpOk('option', 'update', 'gq_payments_site_id', 'site-123');
    await site.wp('transient', 'delete', 'gq_payments_eligible_methods');
    await site.wpOk('transient', 'set', 'gq_payments_eligible_methods', 'mbway', '600');
    await site.wpOk('option', 'update', 'woocommerce_gq_payments_settings', '{"enabled":"yes"}', '--format=json');
    await site.wpOk('option', 'update', 'gq_paymentsish_unrelated', 'keep');
    await site.wp('option', 'delete', 'gq_test_uninstall_http');
    await fetch(`${site.url}/wp-json/gq-test/v1/ping`); // Any signed-route request creates the replay table.
    const replayTable = () => site.wpOk('db', 'query', "SHOW TABLES LIKE 'wp_gq_payments_replay'", '--skip-column-names');
    assert.equal(await replayTable(), 'wp_gq_payments_replay');
    const orderId = await site.wpOk(
      'eval',
      `$order = wc_create_order();
       $order->set_payment_method( 'gq_payments' );
       $order->update_meta_data( '_gq_payments_attempt_id', 'attempt-1' );
       $order->save();
       $order->add_order_note( 'Payment State 3 applied (attempt attempt-1).' );
       echo $order->get_id();`
    );

    await site.wpOk('plugin', 'deactivate', 'gq-payments');
    // --skip-delete: the plugin directory is this checkout, mounted into the site.
    await site.wpOk('plugin', 'uninstall', 'gq-payments', '--skip-delete');

    assert.equal((await site.wp('option', 'get', 'gq_payments_site_id')).ok, false);
    assert.equal((await site.wp('transient', 'get', 'gq_payments_eligible_methods')).stdout, '');
    assert.equal((await site.wp('option', 'get', 'woocommerce_gq_payments_settings')).ok, false);
    assert.equal(await site.wpOk('option', 'get', 'gq_paymentsish_unrelated'), 'keep');
    assert.equal(await replayTable(), '', 'replay table dropped');
    const history = JSON.parse(
      await site.wpOk(
        'eval',
        `$order = wc_get_order( ${Number(orderId)} );
         echo wp_json_encode( [
           'payment_method' => $order->get_payment_method(),
           'attempt'        => $order->get_meta( '_gq_payments_attempt_id' ),
           'notes'          => array_map( fn( $note ) => $note->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ),
         ] );`
      )
    );
    assert.deepEqual(history, {
      payment_method: 'gq_payments',
      attempt: 'attempt-1',
      notes: ['Payment State 3 applied (attempt attempt-1).'],
    });
    assert.equal((await site.wp('option', 'get', 'gq_test_uninstall_http')).ok, false, 'uninstall made HTTP requests');
  });
});
