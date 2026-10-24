// Seam A: the signed Payment State route (ADR 0004) and Payment Service-owned expiry (ADR 0005).
// A shopper checks out through the real Store API, the fake Payment Service pulls the snapshot
// (Payment Start), then sends versioned Payment State messages.
import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { startFakeService, vectors } from './fake-service/fake-service.mjs';
import { connectSite, forgetConnection } from './lib/connect.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';
import { createProduct, shopper } from './lib/store.mjs';

let fake;
let connection;

before(async () => {
  fake = await startFakeService();
  await baseline();
  connection = await connectSite(fake);
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
});
after(async () => {
  await forgetConnection();
  await fake?.close();
});

const mbway = { method: 'gq_payments', data: { gq_method: 'mbway' } };

/** A gq_payments order past Payment Start: 2 × €10.00 from a product with 5 in stock (3 left). */
async function startedOrder() {
  const product = await createProduct(site, { price: '10.00', stock: 5 });
  const s = await shopper();
  const added = await s.addItem(product, 2);
  assert.equal(added.status, 201, added.text);
  const checkout = await s.checkout({ payment: mbway });
  assert.equal(checkout.status, 200, checkout.text);
  const order = { id: checkout.json.order_id, key: checkout.json.order_key, product };
  const snapshot = await fake.callSite({
    method: 'GET',
    route: `/gq-payments/v1/orders/${order.id}/snapshot`,
    query: { key: order.key },
    siteId: connection.siteId,
  });
  assert.equal(snapshot.status, 200, snapshot.text);
  order.revision = snapshot.json.snapshot_revision;
  await site.wpOk('option', 'delete', 'gq_test_emails');
  return order;
}

/** The Payment Service sends the order's Payment State. */
const sendState = (order, state, options = {}) =>
  fake.callSite({
    method: 'POST',
    route: `/gq-payments/v1/orders/${order.id}/payment-state`,
    json: {
      version: 1,
      state: 'pending',
      refunded_total: '0.00',
      method: 'mbway',
      attempt_id: 'att_0001',
      snapshot_revision: order.revision,
      ...state,
    },
    siteId: connection.siteId,
    ...options,
  });

const wcOrder = async ({ id }) => JSON.parse(await site.wpOk('wc', 'shop_order', 'get', String(id), '--user=admin', '--format=json'));
const stock = async ({ product }) => Number(await site.wpOk('wc', 'product', 'get', product, '--field=stock_quantity', '--user=admin'));
const notes = async ({ id }) =>
  JSON.parse(await site.wpOk('eval', `echo wp_json_encode( array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => ${Number(id)}, 'order' => 'ASC' ] ) ) );`));
/** WooCommerce email IDs sent for this order since Payment Start, in order. */
const emails = async ({ id }) => {
  const log = JSON.parse((await site.wp('option', 'get', 'gq_test_emails', '--format=json')).stdout || '[]');
  return log.filter((email) => email.order_id === id).map((email) => email.id);
};

describe('paid', () => {
  test('completes a pending order with the attempt ID as transaction ID, sends the usual emails and keeps the held stock', async () => {
    const order = await startedOrder();

    const response = await sendState(order, { version: 1, state: 'paid', attempt_id: 'att_paid_1' });

    assert.equal(response.status, 200, response.text);
    const paid = await wcOrder(order);
    assert.equal(paid.status, 'processing');
    assert.equal(paid.transaction_id, 'att_paid_1');
    assert.notEqual(paid.date_paid, null);
    assert.deepEqual((await emails(order)).sort(), ['customer_processing_order', 'new_order']);
    assert.equal(await stock(order), 3, 'stock reduced at Payment Start is not reduced again');
    assert.ok(
      (await notes(order)).some((note) => /Payment State "paid".*version 1.*att_paid_1/.test(note)),
      'notes the Payment State change with its version and attempt ID'
    );
  });
});

const stateNotes = async (order) => (await notes(order)).filter((note) => note.startsWith('Payment State'));

describe('versions', () => {
  test('a repeated version is acknowledged without side effects', async () => {
    const order = await startedOrder();
    const paid = { version: 2, state: 'paid', attempt_id: 'att_dup' };
    assert.equal((await sendState(order, paid)).status, 200);
    const before = { notes: await notes(order), emails: await emails(order) };

    const again = await sendState(order, paid);

    assert.equal(again.status, 200, again.text);
    assert.deepEqual(again.json, { order_id: order.id, version: 2, applied: false });
    assert.deepEqual(await notes(order), before.notes);
    assert.deepEqual(await emails(order), before.emails);
    assert.equal((await wcOrder(order)).status, 'processing');
  });

  test('an older version arriving late is acknowledged and ignored', async () => {
    const order = await startedOrder();
    assert.equal((await sendState(order, { version: 3, state: 'paid', attempt_id: 'att_new' })).status, 200);

    const late = await sendState(order, { version: 1, state: 'pending', attempt_id: 'att_old' });

    assert.equal(late.status, 200, late.text);
    assert.deepEqual(late.json, { order_id: order.id, version: 3, applied: false });
    const current = await wcOrder(order);
    assert.equal(current.status, 'processing');
    assert.equal(current.transaction_id, 'att_new');
    assert.equal((await stateNotes(order)).length, 1);
  });

  test('out-of-order delivery converges on the newest state: expired (v3) before paid (v2) stays cancelled', async () => {
    const order = await startedOrder();

    assert.equal((await sendState(order, { version: 3, state: 'expired', attempt_id: 'att_1' })).status, 200);
    const stale = await sendState(order, { version: 2, state: 'paid', attempt_id: 'att_1' });

    assert.equal(stale.json.applied, false);
    assert.equal((await wcOrder(order)).status, 'cancelled');
    assert.equal(await stock(order), 5);
    assert.deepEqual(await emails(order), ['cancelled_order']);
  });

  test('a pending state is recorded with its version without changing the order', async () => {
    const order = await startedOrder();

    const response = await sendState(order, { version: 1, state: 'pending', attempt_id: null });

    assert.deepEqual(response.json, { order_id: order.id, version: 1, applied: true });
    assert.equal((await wcOrder(order)).status, 'pending');
    assert.equal(await stock(order), 3);
    assert.deepEqual(await stateNotes(order), ['Payment State "pending" (version 1, attempt none): recorded.']);
  });
});

describe('expired', () => {
  test("cancels the pending order and restores the stock held since Payment Start; only the store's admin is emailed", async () => {
    const order = await startedOrder();

    const response = await sendState(order, { version: 1, state: 'expired' });

    assert.equal(response.status, 200, response.text);
    assert.equal((await wcOrder(order)).status, 'cancelled');
    assert.equal(await stock(order), 5);
    assert.deepEqual(await emails(order), ['cancelled_order'], "WooCommerce's admin-only Cancelled order email; nothing to the shopper");
    assert.ok((await notes(order)).some((note) => /^Payment State "expired" \(version 1, attempt att_0001\)/.test(note)));
    assert.ok(!(await notes(order)).some((note) => note.includes('not payment evidence')), 'not noted as a manual change');
  });
});

describe('late_payment', () => {
  /** The order's admin edit screen (HPOS or legacy, whichever is active). */
  const orderScreen = async ({ id }) => {
    const url = await site.wpOk('eval', `echo wc_get_order( ${Number(id)} )->get_edit_order_url();`);
    const get = await site.loginAs('admin');
    const page = await get(new URL(url).pathname + new URL(url).search);
    assert.equal(page.status, 200, `order screen ${url}`);
    return page.html;
  };
  const LATE_NOTICE = /class="notice notice-error[^"]*gq-payments-late-payment[^"]*"[\s\S]*?Late Payment/;

  test('after expiry: never changes the status or completes payment; flags, notes and shows an admin notice', async () => {
    const order = await startedOrder();
    assert.equal((await sendState(order, { version: 1, state: 'pending' })).status, 200);
    assert.equal((await sendState(order, { version: 2, state: 'expired' })).status, 200);
    await site.wpOk('option', 'delete', 'gq_test_emails');

    const response = await sendState(order, { version: 3, state: 'late_payment', attempt_id: 'att_late' });

    assert.deepEqual(response.json, { order_id: order.id, version: 3, applied: true });
    const late = await wcOrder(order);
    assert.equal(late.status, 'cancelled');
    assert.equal(late.date_paid, null);
    assert.equal(late.transaction_id, '');
    assert.equal(await stock(order), 5, 'stock released at expiry stays released');
    assert.deepEqual(await emails(order), []);
    assert.ok(
      (await notes(order)).some((note) => /^Payment received after cancellation — review in Banquinha\..*version 3.*att_late/s.test(note)),
      'prominent Late Payment note'
    );
    assert.match(await orderScreen(order), LATE_NOTICE);
  });

  test('a paid state for an order cancelled meanwhile is recorded as a Late Payment, not completed', async () => {
    const order = await startedOrder();
    await site.wpOk('wc', 'shop_order', 'update', String(order.id), '--status=cancelled', '--user=admin');
    await site.wpOk('option', 'delete', 'gq_test_emails');

    assert.equal((await sendState(order, { version: 1, state: 'paid', attempt_id: 'att_after_cancel' })).status, 200);

    const current = await wcOrder(order);
    assert.equal(current.status, 'cancelled');
    assert.equal(current.date_paid, null);
    assert.deepEqual(await emails(order), []);
    assert.ok((await notes(order)).some((note) => note.startsWith('Payment received after cancellation — review in Banquinha.')));
    assert.match(await orderScreen(order), LATE_NOTICE);
  });

  test('no Late Payment notice on an order paid normally', async () => {
    const order = await startedOrder();
    assert.equal((await sendState(order, { version: 1, state: 'paid' })).status, 200);

    assert.doesNotMatch(await orderScreen(order), /gq-payments-late-payment/);
  });
});

describe('paid after an admin edit (Snapshot Revision mismatch)', () => {
  test('still completes the order and notes the amount collected against the current total for review', async () => {
    const order = await startedOrder();
    // A €2.00 fee added after Payment Start through code/REST, which ignore the editing lock.
    await site.wpOk(
      'eval',
      `$order = wc_get_order( ${order.id} ); $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Gift wrap' ); $fee->set_total( '2.00' ); $order->add_item( $fee ); $order->calculate_totals( false ); $order->save();`
    );

    const response = await sendState(order, { version: 1, state: 'paid', attempt_id: 'att_mismatch' });

    assert.equal(response.status, 200, response.text);
    const paid = await wcOrder(order);
    assert.equal(paid.status, 'processing');
    assert.equal(paid.transaction_id, 'att_mismatch');
    assert.deepEqual((await emails(order)).sort(), ['customer_processing_order', 'new_order']);
    assert.ok(
      (await notes(order)).some((note) => note.includes('collected 20.00 EUR, current total 22.00 EUR — review')),
      JSON.stringify(await notes(order))
    );
  });

  test('no review note when the order is unchanged', async () => {
    const order = await startedOrder();

    assert.equal((await sendState(order, { version: 1, state: 'paid' })).status, 200);

    assert.ok(!(await notes(order)).some((note) => note.includes('— review')));
  });
});

describe('forged and unsigned messages', () => {
  const paidBody = (order) => ({ version: 1, state: 'paid', refunded_total: '0.00', method: 'mbway', attempt_id: 'att_forged', snapshot_revision: order.revision });
  const unchanged = async (order) => {
    const current = await wcOrder(order);
    assert.equal(current.status, 'pending');
    assert.equal(current.transaction_id, '');
    assert.deepEqual(await stateNotes(order), []);
    assert.deepEqual(await emails(order), []);
  };

  test('an unsigned message is rejected and changes nothing', async () => {
    const order = await startedOrder();

    const response = await fetch(`${site.url}/wp-json/gq-payments/v1/orders/${order.id}/payment-state`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(paidBody(order)),
    });

    assert.equal(response.status, 401);
    await unchanged(order);
  });

  test('a message signed with a key that is not a Service Key is rejected', async () => {
    const order = await startedOrder();

    const response = await sendState(order, { state: 'paid' }, { secretKey: vectors.stranger_key.secret_key });

    assert.equal(response.status, 401, response.text);
    assert.equal(response.json.code, 'gq_payments_unauthorized');
    await unchanged(order);
  });

  test('a genuine message whose body was altered in transit is rejected', async () => {
    const order = await startedOrder();
    const request = fake.signRequest({ method: 'POST', route: `/gq-payments/v1/orders/${order.id}/payment-state`, json: { ...paidBody(order), state: 'pending' }, siteId: connection.siteId });

    const response = await fake.send({ ...request, body: JSON.stringify(paidBody(order)) });

    assert.equal(response.status, 401, response.text);
    await unchanged(order);
  });

  test('a replayed message is rejected', async () => {
    const order = await startedOrder();
    const request = fake.signRequest({ method: 'POST', route: `/gq-payments/v1/orders/${order.id}/payment-state`, json: { ...paidBody(order), state: 'pending' }, siteId: connection.siteId });
    assert.equal((await fake.send(request)).status, 200);

    assert.equal((await fake.send(request)).status, 401);
  });
});

describe('manual status changes', () => {
  test('an admin marking a started order Processing gets a "not payment evidence" note', async () => {
    const order = await startedOrder();

    await site.wpOk('wc', 'shop_order', 'update', String(order.id), '--status=processing', '--user=admin');

    assert.ok((await notes(order)).some((note) => note.startsWith('Status set manually to Processing — not payment evidence.')));
  });

  test('a later change of a paid order is noted as manual too; the Payment State change itself is not', async () => {
    const order = await startedOrder();
    assert.equal((await sendState(order, { version: 1, state: 'paid' })).status, 200);
    assert.ok(!(await notes(order)).some((note) => note.includes('not payment evidence')));

    await site.wpOk('wc', 'shop_order', 'update', String(order.id), '--status=completed', '--user=admin');

    assert.ok((await notes(order)).some((note) => note.startsWith('Status set manually to Completed — not payment evidence.')));
  });

  test('a paid state for an order already set to Processing by hand leaves the status and notes the verified payment', async () => {
    const order = await startedOrder();
    await site.wpOk('wc', 'shop_order', 'update', String(order.id), '--status=processing', '--user=admin');

    assert.equal((await sendState(order, { version: 1, state: 'paid', attempt_id: 'att_after_manual' })).status, 200);

    assert.equal((await wcOrder(order)).status, 'processing');
    assert.ok((await stateNotes(order)).some((note) => /"paid" \(version 1, attempt att_after_manual\): recorded; the order is Processing/.test(note)));
  });
});

describe('message validation', () => {
  const rejects = async (order, state, field) => {
    const response = await sendState(order, state);
    assert.equal(response.status, 400, `${JSON.stringify(state)}: ${response.text}`);
    assert.equal(response.json.code, 'gq_payments_invalid_payment_state');
    assert.equal(response.json.data.field, field);
  };

  test('malformed fields are rejected with the field name and change nothing', async () => {
    const order = await startedOrder();

    await rejects(order, { version: 0 }, 'version');
    await rejects(order, { version: '2' }, 'version');
    await rejects(order, { state: 'refunded' }, 'state');
    await rejects(order, { refunded_total: '-1.00' }, 'refunded_total');
    await rejects(order, { refunded_total: '1.001', state: 'paid' }, 'refunded_total');
    await rejects(order, { refunded_total: 5 }, 'refunded_total');
    await rejects(order, { refunded_total: '1.00', state: 'pending' }, 'refunded_total');
    await rejects(order, { method: 'MB WAY' }, 'method');
    await rejects(order, { state: 'paid', attempt_id: '' }, 'attempt_id');
    await rejects(order, { snapshot_revision: 'abc' }, 'snapshot_revision');

    assert.equal((await wcOrder(order)).status, 'pending');
    assert.deepEqual(await stateNotes(order), []);
  });

  test('the cumulative refunded total never decreases nor exceeds the amount collected; no WooCommerce refund is created', async () => {
    const order = await startedOrder();
    assert.equal((await sendState(order, { version: 1, state: 'paid' })).status, 200);

    await rejects(order, { version: 2, state: 'paid', refunded_total: '20.01' }, 'refunded_total');
    assert.equal((await sendState(order, { version: 2, state: 'paid', refunded_total: '5.00' })).status, 200);
    await rejects(order, { version: 3, state: 'paid', refunded_total: '4.99' }, 'refunded_total');

    const current = await wcOrder(order);
    assert.deepEqual(current.refunds, []);
    assert.equal(current.status, 'processing');
  });

  test('unknown orders, other gateways and orders not yet started are refused', async () => {
    const product = await createProduct(site, { stock: 5 });
    const s = await shopper();
    await s.addItem(product, 1);
    const checkout = await s.checkout({ payment: mbway });
    const notStarted = { id: checkout.json.order_id, revision: 'a'.repeat(64) };
    const cheque = { id: Number(await site.wpOk('wc', 'shop_order', 'create', '--payment_method=cheque', '--status=pending', '--user=admin', '--porcelain')), revision: 'a'.repeat(64) };

    assert.equal((await sendState({ id: 999999, revision: 'a'.repeat(64) }, {})).status, 404);
    assert.equal((await sendState(cheque, {})).status, 404);
    const early = await sendState(notStarted, {});
    assert.equal(early.status, 409, early.text);
    assert.equal(early.json.code, 'gq_payments_payment_not_started');
  });
});

describe("gq-config's restricted REST mode", () => {
  before(async () => {
    await site.wpOk('option', 'update', 'gq_test_anonymous_rest_mode', 'restricted');
    await site.wpOk('option', 'update', 'gq_test_public_rest_routes', '{"/wc/store/v1/*":["GET","POST","OPTIONS"]}', '--format=json');
  });
  after(async () => {
    await site.wp('option', 'delete', 'gq_test_anonymous_rest_mode');
    await site.wp('option', 'delete', 'gq_test_public_rest_routes');
  });

  test('the Payment State route is on the allow-list', async () => {
    const order = await startedOrder();

    const response = await sendState(order, { version: 1, state: 'paid' });

    assert.equal(response.status, 200, response.text);
    assert.equal((await wcOrder(order)).status, 'processing');
  });
});
