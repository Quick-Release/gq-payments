// gq_payments orders driven end to end: a Store API checkout, the fake Payment Service's
// snapshot pull (Payment Start) and its signed Payment State messages, plus read-only views of
// the resulting WooCommerce order through WP-CLI.
import assert from 'node:assert/strict';
import { singleSite as site } from './site.mjs';
import { createProduct, shopper } from './store.mjs';

export const mbway = { method: 'gq_payments', data: { gq_method: 'mbway' } };

/**
 * Binds the helpers to a running fake Payment Service and the site's connection.
 *
 * @param {object} fake       From startFakeService().
 * @param {object} connection From connectSite().
 */
export function paymentOrders(fake, connection) {
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

  return { startedOrder, sendState };
}

/** The order as the WooCommerce REST schema shows it (status, refunds, transaction_id, …). */
export const wcOrder = async ({ id }) => JSON.parse(await site.wpOk('wc', 'shop_order', 'get', String(id), '--user=admin', '--format=json'));

/** Stock left of the order's product. */
export const stock = async ({ product }) => Number(await site.wpOk('wc', 'product', 'get', product, '--field=stock_quantity', '--user=admin'));

/** The order's notes, oldest first. */
export const notes = async ({ id }) =>
  JSON.parse(await site.wpOk('eval', `echo wp_json_encode( array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => ${Number(id)}, 'order' => 'ASC' ] ) ) );`));

/** WooCommerce email IDs sent for this order since Payment Start, in order. */
export const emails = async ({ id }) => {
  const log = JSON.parse((await site.wp('option', 'get', 'gq_test_emails', '--format=json')).stdout || '[]');
  return log.filter((email) => email.order_id === id).map((email) => email.id);
};
