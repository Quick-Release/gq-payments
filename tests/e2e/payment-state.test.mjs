// Seam A: the signed Payment State route (ADR 0004) and Payment Service-owned expiry (ADR 0005).
// A shopper checks out through the real Store API, the fake Payment Service pulls the snapshot
// (Payment Start), then sends versioned Payment State messages.
import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { startFakeService } from './fake-service/fake-service.mjs';
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
