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

  test('the gateway cannot refund: it declares no refunds support', async () => {
    const s = await shopperWithCart();
    const checkout = await s.checkout({ payment: mbway });
    assert.equal(checkout.status, 200, checkout.text);

    const refund = await site.wp('wc', 'shop_order_refund', 'create', String(checkout.json.order_id), '--amount=1', '--api_refund=true', '--user=admin');

    assert.equal(refund.ok, false);
    assert.match(refund.stderr, /does not support automatic refunds/);
  });

  test('a zero-total order completes through the normal WooCommerce flow, without the gateway', async () => {
    const free = await createProduct(site, { price: '0', stock: 5, name: 'Sticker' });
    const s = await shopperWithCart(free);

    const checkout = await s.checkout();

    assert.equal(checkout.status, 200, checkout.text);
    const placed = await order(checkout.json.order_id);
    assert.equal(placed.status, 'processing');
    assert.equal(placed.payment_method, '');
    assert.equal(fake.calls.length, 0);
  });

  const eligible = (methods) => site.wpOk('option', 'update', 'gq_test_eligible_methods', JSON.stringify(methods), '--format=json');
  const offered = async (s) => (await s.cart()).json.payment_methods;

  test('the gateway is unavailable while the site is not connected', async () => {
    await site.wpOk('option', 'delete', 'gq_payments_site_id');
    const s = await shopperWithCart();

    assert.ok(!(await offered(s)).includes('gq_payments'));
    const checkout = await s.checkout({ payment: mbway });
    assert.equal(checkout.status, 400, checkout.text);
  });

  test('the gateway is unavailable while no method is eligible', async () => {
    await eligible([]);
    const s = await shopperWithCart();

    assert.ok(!(await offered(s)).includes('gq_payments'));
    const checkout = await s.checkout({ payment: mbway });
    assert.equal(checkout.status, 400, checkout.text);
  });

  test('a method that is not eligible is refused while another is offered', async () => {
    await eligible(['multibanco']);
    const s = await shopperWithCart();
    assert.ok((await offered(s)).includes('gq_payments'));

    const refused = await s.checkout({ payment: mbway });
    assert.equal(refused.status, 400, refused.text);

    const accepted = await s.checkout({ payment: { method: 'gq_payments', data: { gq_method: 'multibanco' } } });
    assert.equal(accepted.status, 200, accepted.text);
    assert.equal((await order(accepted.json.order_id)).payment_method_title, 'Multibanco');
  });

  test('a checkout without a known method is refused', async () => {
    for (const data of [{}, { gq_method: 'bitcoin' }]) {
      const s = await shopperWithCart();
      const checkout = await s.checkout({ payment: { method: 'gq_payments', data } });
      assert.equal(checkout.status, 400, checkout.text);
    }
  });
});

describe("hidden from WordPress's own checkout pages", () => {
  let blockCheckoutPage;
  before(async () => {
    blockCheckoutPage = await site.wpOk('option', 'get', 'woocommerce_checkout_page_id');
    // Cheque proves the pages do list available gateways.
    await site.wpOk('option', 'update', 'woocommerce_cheque_settings', '{"enabled":"yes","title":"Check payments"}', '--format=json');
  });
  after(async () => {
    await site.wpOk('option', 'update', 'woocommerce_checkout_page_id', blockCheckoutPage);
    await site.wp('option', 'delete', 'woocommerce_cheque_settings');
  });

  /** An anonymous browser session with the product in its cart; resolves get(path) → HTML. */
  async function browserWithCart() {
    const jar = new Map();
    const get = async (path) => {
      const response = await fetch(`${site.url}${path}`, { redirect: 'manual', headers: { cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; ') } });
      for (const cookie of response.headers.getSetCookie()) {
        const [pair] = cookie.split(';');
        jar.set(pair.slice(0, pair.indexOf('=')), pair.slice(pair.indexOf('=') + 1));
      }
      const location = response.headers.get('location');
      return location ? get(new URL(location, site.url).pathname + new URL(location, site.url).search) : response.text();
    };
    await get(`/?add-to-cart=${product}`);
    return get;
  }

  test('the classic (shortcode) checkout does not offer gq_payments', async () => {
    const page = await site.wpOk('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Classic checkout', '--post_content=[woocommerce_checkout]', '--porcelain');
    await site.wpOk('option', 'update', 'woocommerce_checkout_page_id', page);
    const get = await browserWithCart();

    const html = await get(`/?page_id=${page}`);
    await site.wpOk('post', 'delete', page, '--force');

    assert.match(html, /id="payment_method_cheque"/);
    assert.doesNotMatch(html, /payment_method_gq_payments/);
  });

  test('the block checkout has no gq_payments payment method to render', async () => {
    const get = await browserWithCart();

    const html = await get(`/?page_id=${blockCheckoutPage}`);

    const settings = JSON.parse(decodeURIComponent(html.match(/var wcSettings = JSON\.parse\( decodeURIComponent\( '([^']+)' \) \)/)[1]));
    assert.deepEqual(Object.keys(settings.paymentMethodData), ['cheque']);
  });
});
