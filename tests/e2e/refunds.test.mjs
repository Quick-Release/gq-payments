// Seam A: verified refunds mirrored from Payment State (ADR 0004), and WooCommerce refunds
// created anywhere else blocked on gq_payments orders (ADR 0001).
import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { startFakeService } from './fake-service/fake-service.mjs';
import { connectSite, forgetConnection } from './lib/connect.mjs';
import { emails, notes, paymentOrders, stock, wcOrder } from './lib/orders.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';

let fake;
let connection;
let orders;

before(async () => {
  fake = await startFakeService();
  await baseline();
  connection = await connectSite(fake);
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  orders = paymentOrders(fake, connection);
});
after(async () => {
  await forgetConnection();
  await fake?.close();
});

/** A €20.00 order the Payment Service reported paid (version 1); 3 of 5 left in stock. */
async function paidOrder() {
  const order = await orders.startedOrder();
  const paid = await orders.sendState(order, { version: 1, state: 'paid', attempt_id: 'att_paid' });
  assert.equal(paid.status, 200, paid.text);
  await site.wpOk('option', 'delete', 'gq_test_emails');
  return order;
}

const refundedState = (version, refunded_total) => ({ version, state: 'paid', attempt_id: 'att_paid', refunded_total });

/** The order's WooCommerce refunds, oldest first: amount, line items, and whether money moved through a gateway. */
const refunds = async ({ id }) =>
  JSON.parse(
    await site.wpOk(
      'eval',
      `echo wp_json_encode( array_map( fn( $r ) => [ 'amount' => $r->get_amount(), 'items' => count( $r->get_items() ), 'refunded_payment' => $r->get_refunded_payment() ], array_reverse( wc_get_order( ${Number(id)} )->get_refunds() ) ) );`
    )
  );
const refundedTotal = async (order) => (await wcOrder(order)).refunds.reduce((sum, refund) => sum - Number(refund.total), 0).toFixed(2);

describe('mirroring verified refunds', () => {
  test('a partial then a full refund each become one WooCommerce refund of the increase, without restocking', async () => {
    const order = await paidOrder();

    const partial = await orders.sendState(order, refundedState(2, '5.00'));

    assert.equal(partial.status, 200, partial.text);
    assert.deepEqual(await refunds(order), [{ amount: '5.00', items: 0, refunded_payment: false }]);
    assert.equal((await wcOrder(order)).status, 'processing');
    assert.equal(await stock(order), 3, 'a mirrored refund never restocks');
    assert.ok(
      (await notes(order)).some((note) => /Refund of 5\.00 EUR verified by the Payment Service.*Banquinha/.test(note)),
      JSON.stringify(await notes(order))
    );
    assert.deepEqual(await emails(order), ['customer_partially_refunded_order']);

    const full = await orders.sendState(order, refundedState(3, '20.00'));

    assert.equal(full.status, 200, full.text);
    assert.deepEqual(await refunds(order), [
      { amount: '5.00', items: 0, refunded_payment: false },
      { amount: '15.00', items: 0, refunded_payment: false },
    ]);
    assert.equal(await refundedTotal(order), '20.00');
    assert.equal(await stock(order), 3);
    // WooCommerce moves a fully refunded order to Refunded; that change came from Payment State.
    assert.equal((await wcOrder(order)).status, 'refunded');
    assert.ok(!(await notes(order)).some((note) => note.includes('not payment evidence')), 'not noted as a manual change');
    assert.deepEqual(await emails(order), ['customer_partially_refunded_order', 'customer_refunded_order']);
  });

  test('a repeated refund message creates no second refund', async () => {
    const order = await paidOrder();
    assert.equal((await orders.sendState(order, refundedState(2, '5.00'))).status, 200);

    const again = await orders.sendState(order, refundedState(2, '5.00'));

    assert.equal(again.status, 200, again.text);
    assert.equal(again.json.applied, false);
    assert.deepEqual(await refunds(order), [{ amount: '5.00', items: 0, refunded_payment: false }]);
  });

  test('a newer version with the same refunded total creates no refund', async () => {
    const order = await paidOrder();
    assert.equal((await orders.sendState(order, refundedState(2, '5.00'))).status, 200);

    assert.equal((await orders.sendState(order, refundedState(3, '5.00'))).status, 200);

    assert.deepEqual(await refunds(order), [{ amount: '5.00', items: 0, refunded_payment: false }]);
  });

  test('reordered messages converge on the newest refunded total', async () => {
    const order = await paidOrder();

    assert.equal((await orders.sendState(order, refundedState(4, '12.00'))).status, 200);
    const stale = [refundedState(2, '5.00'), refundedState(3, '8.00')];
    for (const state of stale) {
      const response = await orders.sendState(order, state);
      assert.equal(response.status, 200, response.text);
      assert.equal(response.json.applied, false);
    }

    assert.deepEqual(await refunds(order), [{ amount: '12.00', items: 0, refunded_payment: false }]);
    assert.equal(await refundedTotal(order), '12.00');
  });

  test('a paid state that already carries a refund completes the order and mirrors the refund', async () => {
    const order = await orders.startedOrder();

    const response = await orders.sendState(order, { version: 1, state: 'paid', attempt_id: 'att_paid', refunded_total: '20.00' });

    assert.equal(response.status, 200, response.text);
    const current = await wcOrder(order);
    assert.equal(current.transaction_id, 'att_paid');
    assert.equal(current.status, 'refunded');
    assert.equal(await refundedTotal(order), '20.00');
    assert.equal(await stock(order), 3);
  });

  test('a refund of a Late Payment is mirrored without changing the cancelled status', async () => {
    const order = await orders.startedOrder();
    assert.equal((await orders.sendState(order, { version: 1, state: 'expired' })).status, 200);
    assert.equal((await orders.sendState(order, { version: 2, state: 'late_payment', attempt_id: 'att_late' })).status, 200);

    const response = await orders.sendState(order, { version: 3, state: 'late_payment', attempt_id: 'att_late', refunded_total: '20.00' });

    assert.equal(response.status, 200, response.text);
    assert.deepEqual(await refunds(order), [{ amount: '20.00', items: 0, refunded_payment: false }]);
    const current = await wcOrder(order);
    assert.equal(current.date_paid, null);
    assert.equal(current.status, 'cancelled', 'a Late Payment never changes the order status, even when fully refunded');
    assert.ok((await notes(order)).some((note) => /Refund of 20\.00 EUR verified by the Payment Service/.test(note)), JSON.stringify(await notes(order)));
    assert.equal(await stock(order), 5, 'stock released at expiry stays released');
  });

  test('a Late Payment flagged and fully refunded in one message stays cancelled', async () => {
    const order = await orders.startedOrder();
    assert.equal((await orders.sendState(order, { version: 1, state: 'expired' })).status, 200);

    const response = await orders.sendState(order, { version: 2, state: 'paid', attempt_id: 'att_late', refunded_total: '20.00' });

    assert.equal(response.status, 200, response.text);
    assert.equal(await refundedTotal(order), '20.00');
    assert.equal((await wcOrder(order)).status, 'cancelled');
  });
});

describe('refunds created outside Payment State', () => {
  const BLOCKED = /Refunds for this order are made in Banquinha/;

  test('the WooCommerce REST API cannot refund the order', async () => {
    const order = await paidOrder();
    const password = await site.wpOk('user', 'application-password', 'create', 'admin', `refund-test-${order.id}`, '--porcelain');

    const response = await fetch(`${site.url}/wp-json/wc/v3/orders/${order.id}/refunds`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Basic ${Buffer.from(`admin:${password}`).toString('base64')}` },
      body: JSON.stringify({ amount: '5.00', api_refund: false }),
    });

    const text = await response.text();
    assert.ok(response.status >= 400, `${response.status} ${text}`);
    assert.match(text, BLOCKED);
    assert.deepEqual(await refunds(order), []);
    assert.equal((await wcOrder(order)).status, 'processing');
  });

  test("another plugin's wc_create_refund() call is refused", async () => {
    const order = await paidOrder();

    const result = await site.wpOk(
      'eval',
      `$r = wc_create_refund( [ 'order_id' => ${order.id}, 'amount' => '5.00' ] ); echo is_wp_error( $r ) ? $r->get_error_message() : 'created';`
    );

    assert.match(result, BLOCKED);
    assert.deepEqual(await refunds(order), []);
  });

  test("marking the order Refunded by hand creates no WooCommerce refund and restores no stock", async () => {
    const order = await paidOrder();

    await site.wpOk('wc', 'shop_order', 'update', String(order.id), '--status=refunded', '--user=admin');

    assert.equal((await wcOrder(order)).status, 'refunded');
    assert.deepEqual(await refunds(order), []);
    assert.equal(await stock(order), 3);
    assert.ok((await notes(order)).some((note) => note.startsWith('Status set manually to Refunded — not payment evidence.')));
  });

  test('refunds on orders of other gateways are not affected', async () => {
    const id = await site.wpOk('wc', 'shop_order', 'create', '--payment_method=cheque', '--status=processing', '--user=admin', '--porcelain');
    await site.wpOk('eval', `$o = wc_get_order( ${id} ); $f = new WC_Order_Item_Fee(); $f->set_name( 'Item' ); $f->set_total( '10.00' ); $o->add_item( $f ); $o->calculate_totals( false ); $o->save();`);

    const result = await site.wpOk('eval', `$r = wc_create_refund( [ 'order_id' => ${id}, 'amount' => '5.00' ] ); echo is_wp_error( $r ) ? $r->get_error_message() : 'created';`);

    assert.equal(result, 'created');
  });
});
