// Seam A: the signed Order Snapshot (ADR 0003) and Payment Start (ADR 0005).
// A shopper checks out through the real Store API; the fake Payment Service pulls the snapshot.
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService, TEST_SITE_ID } from './fake-service/fake-service.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';
import { createProduct, shopper } from './lib/store.mjs';

let fake;
const setup = {};

// Store set-up with every snapshot component: 23% VAT, a 10% coupon and €5.00 flat-rate shipping.
before(async () => {
  fake = await startFakeService();
  await baseline();
  await site.wpOk('option', 'update', 'gq_payments_site_id', TEST_SITE_ID);
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  await site.wpOk('option', 'update', 'woocommerce_calc_taxes', 'yes');
  setup.taxRate = await site.wpOk('wc', 'tax', 'create', '--country=PT', '--rate=23', '--name=IVA', '--shipping=true', '--user=admin', '--porcelain');
  setup.shipping = await site.wpOk('wc', 'shipping_zone_method', 'create', '0', '--method_id=flat_rate', '--settings={"cost":"5.00","tax_status":"taxable"}', '--user=admin', '--porcelain');
  setup.coupon = await site.wpOk('wc', 'shop_coupon', 'create', '--code=ten', '--discount_type=percent', '--amount=10', '--user=admin', '--porcelain');
});
afterEach(() => fake.reset());
after(async () => {
  await site.wp('wc', 'shop_coupon', 'delete', setup.coupon, '--force=true', '--user=admin');
  await site.wp('wc', 'shipping_zone_method', 'delete', '0', setup.shipping, '--force=true', '--user=admin');
  await site.wp('wc', 'tax', 'delete', setup.taxRate, '--force=true', '--user=admin');
  await site.wp('option', 'update', 'woocommerce_calc_taxes', 'no');
  await site.wp('option', 'delete', 'gq_payments_site_id');
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
  fake.callSite({ method: 'GET', route: `/gq-payments/v1/orders/${id}/snapshot`, query: key === undefined ? {} : { key }, ...options });

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
});
