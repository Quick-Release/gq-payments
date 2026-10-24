// Seam A: the checkout handoff. A shopper checks out through the real Store API with the hidden
// gq_payments gateway; the fake Payment Service then pulls the signed Order Snapshot (ADR 0003).
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService, TEST_SITE_ID } from './fake-service/fake-service.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';
import { createProduct, shopper } from './lib/store.mjs';

let fake;
let product;

before(async () => {
  fake = await startFakeService();
  await baseline();
  await site.wpOk('option', 'update', 'gq_payments_site_id', TEST_SITE_ID);
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  product = await createProduct(site, { price: '10.00', stock: 50 });
});
afterEach(async () => {
  fake.reset();
  await site.wpOk('option', 'update', 'gq_payments_site_id', TEST_SITE_ID);
  await site.wp('option', 'delete', 'gq_test_eligible_methods');
});
after(async () => {
  await site.wp('option', 'delete', 'gq_payments_site_id');
  await fake?.close();
});

const order = async (id) => JSON.parse(await site.wpOk('wc', 'shop_order', 'get', String(id), '--user=admin', '--format=json'));
const mbway = { method: 'gq_payments', data: { gq_method: 'mbway' } };

/** A shopper with one item in the cart; resolves the shopper. */
async function shopperWithCart(id = product, quantity = 1) {
  const s = await shopper();
  const added = await s.addItem(id, quantity);
  assert.equal(added.status, 201, added.text);
  return s;
}

describe('hidden gq_payments gateway through the Store API', () => {
  test('checkout with an eligible method creates a Pending payment order titled with the method', async () => {
    const s = await shopperWithCart();

    const checkout = await s.checkout({ payment: mbway });

    assert.equal(checkout.status, 200, checkout.text);
    assert.equal(checkout.json.payment_result.payment_status, 'success');
    assert.equal(checkout.json.payment_result.redirect_url, '');
    const placed = await order(checkout.json.order_id);
    assert.equal(placed.status, 'pending');
    assert.equal(placed.payment_method, 'gq_payments');
    assert.equal(placed.payment_method_title, 'MB WAY');
    assert.equal(fake.calls.length, 0, 'checkout calls no external service');
  });
});
