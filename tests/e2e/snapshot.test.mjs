// Seam A: the signed Order Snapshot (ADR 0003) and Payment Start (ADR 0005).
// A shopper checks out through the real Store API; the fake Payment Service pulls the snapshot.
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService } from './fake-service/fake-service.mjs';
import { connectSite, forgetConnection } from './lib/connect.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';
import { createProduct, shopper } from './lib/store.mjs';

let fake;
let connection;
const setup = {};

// Store set-up with every snapshot component: 23% VAT, a 10% coupon and €5.00 flat-rate shipping.
before(async () => {
  fake = await startFakeService();
  await baseline();
  connection = await connectSite(fake);
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  await site.wpOk('option', 'update', 'woocommerce_calc_taxes', 'yes');
  setup.taxRate = await site.wpOk('wc', 'tax', 'create', '--country=PT', '--rate=23', '--name=IVA', '--shipping=true', '--user=admin', '--porcelain');
  setup.shipping = await site.wpOk('wc', 'shipping_zone_method', 'create', '0', '--method_id=flat_rate', '--settings={"cost":"5.00","tax_status":"taxable"}', '--user=admin', '--porcelain');
  setup.coupon = await site.wpOk('wc', 'shop_coupon', 'create', '--code=ten', '--discount_type=percent', '--amount=10', '--user=admin', '--porcelain');
});
afterEach(() => {
  fake.calls.length = 0;
});
after(async () => {
  await site.wp('wc', 'shop_coupon', 'delete', setup.coupon, '--force=true', '--user=admin');
  // The REST/CLI delete cannot address the "rest of the world" zone (ID 0).
  await site.wp('eval', `(new WC_Shipping_Zone( 0 ))->delete_shipping_method( ${Number(setup.shipping)} );`);
  await site.wp('wc', 'tax', 'delete', setup.taxRate, '--force=true', '--user=admin');
  await site.wp('option', 'update', 'woocommerce_calc_taxes', 'no');
  await forgetConnection();
  await fake?.close();
});

const mbway = { method: 'gq_payments', data: { gq_method: 'mbway' } };

/** Places a gq_payments order through the Store API; resolves { id, key, product }. */
async function placeOrder({ price = '10.00', quantity = 2, stock = 10, coupon = 'ten', payment = mbway } = {}) {
  const product = await createProduct(site, { price, stock });
  const s = await shopper();
  const added = await s.addItem(product, quantity);
  assert.equal(added.status, 201, added.text);
  if (coupon) {
    const applied = await s.request('POST', '/cart/apply-coupon', { code: coupon });
    assert.equal(applied.status, 200, applied.text);
  }
  const checkout = await s.checkout({ payment });
  assert.equal(checkout.status, 200, checkout.text);
  return { id: checkout.json.order_id, key: checkout.json.order_key, product };
}

/** The Payment Service fetches the order's snapshot. */
const snapshot = ({ id, key }, options = {}) =>
  fake.callSite({ method: 'GET', route: `/gq-payments/v1/orders/${id}/snapshot`, query: key === undefined ? {} : { key }, siteId: connection.siteId, ...options });

describe('signed Order Snapshot', () => {
  test('carries totals only: items after discount, VAT, shipping, fees, gross total, currency, status and revision', async () => {
    const order = await placeOrder();

    const response = await snapshot(order);

    assert.equal(response.status, 200, response.text);
    const { snapshot_revision: revision, ...rest } = response.json;
    // 2 × €10.00 − 10% = €18.00 net + 23% VAT €4.14; shipping €5.00 + €1.15 VAT; total €28.29.
    assert.deepEqual(rest, {
      order_id: order.id,
      currency: 'EUR',
      status: 'pending',
      totals: {
        items_net: '18.00',
        items_tax: '4.14',
        shipping_net: '5.00',
        shipping_tax: '1.15',
        fees_net: '0.00',
        fees_tax: '0.00',
        total: '28.29',
      },
    });
    assert.match(revision, /^[0-9a-f]{64}$/);
  });

  const notFound = { code: 'gq_payments_order_not_found', message: 'Order not found.', data: { status: 404 } };

  test('requires the order key: a missing or wrong key reads like an unknown order', async () => {
    const order = await placeOrder({ coupon: null });

    for (const key of [undefined, '', 'wc_order_wrong', order.key.toUpperCase()]) {
      const response = await snapshot({ ...order, key });
      assert.equal(response.status, 404, `key ${key}: ${response.text}`);
      assert.deepEqual(response.json, notFound);
    }
  });

  test('refuses unknown orders and orders not paid through gq_payments', async () => {
    const cheque = await site.wpOk('wc', 'shop_order', 'create', '--payment_method=cheque', '--status=pending', '--user=admin', '--porcelain');
    const chequeKey = JSON.parse(await site.wpOk('wc', 'shop_order', 'get', cheque, '--user=admin', '--format=json')).order_key;

    for (const target of [{ id: 999999, key: 'wc_order_x' }, { id: Number(cheque), key: chequeKey }]) {
      const response = await snapshot(target);
      assert.equal(response.status, 404, response.text);
      assert.deepEqual(response.json, notFound);
    }
  });

  test('only the Payment Service can read it: unsigned and foreign-site requests are refused', async () => {
    const order = await placeOrder({ coupon: null });

    const unsigned = await fetch(`${site.url}/wp-json/gq-payments/v1/orders/${order.id}/snapshot?key=${order.key}`);
    const foreign = await snapshot(order, { siteId: '00000000-0000-4000-8000-000000000000' });

    assert.equal(unsigned.status, 401);
    assert.equal(foreign.status, 401, foreign.text);
    assert.equal(foreign.json.code, 'gq_payments_unauthorized');
  });
});

describe('Payment Start', () => {
  const wcOrder = async (id) => JSON.parse(await site.wpOk('wc', 'shop_order', 'get', String(id), '--user=admin', '--format=json'));
  const stock = async (product) => Number(await site.wpOk('wc', 'product', 'get', product, '--field=stock_quantity', '--user=admin'));

  test('locks the order: it stays Pending payment but is no longer editable', async () => {
    const order = await placeOrder({ coupon: null });
    assert.equal((await wcOrder(order.id)).is_editable, true, 'editable before Payment Start');

    const response = await snapshot(order);

    assert.equal(response.status, 200, response.text);
    const locked = await wcOrder(order.id);
    assert.equal(locked.status, 'pending');
    assert.equal(locked.is_editable, false);
  });

  test('reduces stock exactly once, however often the snapshot is fetched', async () => {
    const order = await placeOrder({ quantity: 2, stock: 5, coupon: null });
    assert.equal(await stock(order.product), 5, 'checkout alone only reserves stock');

    assert.equal((await snapshot(order)).status, 200);
    assert.equal(await stock(order.product), 3);

    assert.equal((await snapshot(order)).status, 200);
    assert.equal((await snapshot(order)).status, 200);
    assert.equal(await stock(order.product), 3);
  });

  test("releases the checkout's stock reservation so held stock is not counted twice", async () => {
    const order = await placeOrder({ quantity: 2, stock: 5, coupon: null });
    assert.equal((await snapshot(order)).status, 200);

    // 3 left in stock: another shopper can take all 3 only if the 2 reserved at checkout were released.
    const other = await shopper();
    const added = await other.addItem(order.product, 3);
    assert.equal(added.status, 201, added.text);
    const checkout = await other.checkout({ payment: mbway });
    assert.equal(checkout.status, 200, checkout.text);
  });
});

describe('Snapshot Revision', () => {
  test('is stable while totals are unchanged and changes when they change', async () => {
    const order = await placeOrder();
    const first = await snapshot(order);
    const again = await snapshot(order);
    assert.equal(again.json.snapshot_revision, first.json.snapshot_revision);

    // A fee added after Payment Start (e.g. through the REST API, which ignores the editing lock).
    await site.wpOk(
      'eval',
      `$order = wc_get_order( ${order.id} ); $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Gift wrap' ); $fee->set_total( '2.00' ); $fee->set_tax_status( 'taxable' ); $order->add_item( $fee ); $order->calculate_totals(); $order->save();`
    );
    const changed = await snapshot(order);

    assert.notEqual(changed.json.snapshot_revision, first.json.snapshot_revision);
    // €2.00 fee + 23% VAT €0.46: total €28.29 → €30.75.
    assert.equal(changed.json.totals.fees_net, '2.00');
    assert.equal(changed.json.totals.fees_tax, '0.46');
    assert.equal(changed.json.totals.total, '30.75');
  });
});

describe("WooCommerce's unpaid-order cancellation", () => {
  before(async () => {
    await site.wpOk('option', 'update', 'woocommerce_hold_stock_minutes', '1');
  });
  after(async () => {
    await site.wp('option', 'update', 'woocommerce_hold_stock_minutes', '60');
  });

  const status = async (id) => site.wpOk('wc', 'shop_order', 'get', String(id), '--field=status', '--user=admin');
  // Two hours since the last update, in both storages (the CRUD save always stamps "now").
  const backdate = (id) =>
    site.wpOk(
      'eval',
      `global $wpdb; $t = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
       $wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $t, 'post_modified' => $t ], [ 'ID' => ${Number(id)} ] );
       if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" ) ) { $wpdb->update( $wpdb->prefix . 'wc_orders', [ 'date_updated_gmt' => $t ], [ 'id' => ${Number(id)} ] ); }`
    );

  test('never cancels gq_payments orders, before or after Payment Start', async () => {
    const started = await placeOrder({ coupon: null });
    assert.equal((await snapshot(started)).status, 200);
    const notStarted = await placeOrder({ coupon: null });
    // Control: another gateway's unpaid checkout order is cancelled by the same run.
    const cheque = {
      id: await site.wpOk('eval', "$o = wc_create_order( [ 'status' => 'pending', 'created_via' => 'store-api' ] ); $o->set_payment_method( 'cheque' ); $o->save(); echo $o->get_id();"),
    };
    for (const order of [started, notStarted, cheque]) await backdate(order.id);

    await site.wpOk('eval', 'wc_cancel_unpaid_orders();');

    assert.equal(await status(cheque.id), 'cancelled');
    assert.equal(await status(started.id), 'pending');
    assert.equal(await status(notStarted.id), 'pending');
  });
});

describe("gq-config's restricted REST mode", () => {
  before(async () => {
    await site.wpOk('option', 'update', 'gq_test_anonymous_rest_mode', 'restricted');
    // The storefront's own allow-list entry for the Store API (added by another plugin in production).
    await site.wpOk('option', 'update', 'gq_test_public_rest_routes', '{"/wc/store/v1/*":["GET","POST","OPTIONS"]}', '--format=json');
  });
  after(async () => {
    await site.wp('option', 'delete', 'gq_test_anonymous_rest_mode');
    await site.wp('option', 'delete', 'gq_test_public_rest_routes');
  });

  test('the snapshot route is on the allow-list', async () => {
    const order = await placeOrder({ coupon: null });

    const response = await snapshot(order);

    assert.equal(response.status, 200, response.text);
  });

  test('the allow-list entry does not open other gq-payments methods or routes', async () => {
    const order = await placeOrder({ coupon: null });

    const post = await fake.callSite({ method: 'POST', route: `/gq-payments/v1/orders/${order.id}/snapshot`, json: {} });

    assert.equal(post.status, 401, post.text);
    assert.equal(post.json.code, 'getquick_rest_auth_required');
  });
});
