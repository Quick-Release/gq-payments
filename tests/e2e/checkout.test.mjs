// Seam A: the checkout handoff. A shopper checks out through the real Store API with the hidden
// gq_payments gateway; the fake Payment Service then pulls the signed Order Snapshot (ADR 0003).
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService } from './fake-service/fake-service.mjs';
import { connectSite, forgetConnection, MBWAY, MULTIBANCO } from './lib/connect.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';
import { createProduct, shopper } from './lib/store.mjs';

let fake;
let connection;
let product;

before(async () => {
  fake = await startFakeService();
  await baseline();
  connection = await connectSite(fake, { methods: [MBWAY, MULTIBANCO] });
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  product = await createProduct(site, { price: '10.00', stock: 50 });
  fake.calls.length = 0;
});
afterEach(async () => {
  fake.calls.length = 0;
  connection.service.setMethods([MBWAY, MULTIBANCO]);
  connection.service.approve();
  await connection.refresh();
});
after(async () => {
  await forgetConnection();
  await fake?.close();
});

/** Outbound calls other than the connection's own status refreshes. */
const serviceCalls = () => fake.calls.filter((call) => call.path !== '/v1/site/status');

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
    assert.deepEqual(serviceCalls(), [], 'checkout calls no external service');
  });

  test('a refund through the gateway is refused: refunds are made in Banquinha', async () => {
    const s = await shopperWithCart();
    const checkout = await s.checkout({ payment: mbway });
    assert.equal(checkout.status, 200, checkout.text);

    const refund = await site.wp('wc', 'shop_order_refund', 'create', String(checkout.json.order_id), '--amount=1', '--api_refund=true', '--user=admin');

    assert.equal(refund.ok, false);
    // Refused before the gateway is asked (it declares no refunds support either).
    assert.match(refund.stderr, /Refunds for this order are made in Banquinha/);
    assert.deepEqual((await order(checkout.json.order_id)).refunds, []);
  });

  test('a zero-total order completes through the normal WooCommerce flow, without the gateway', async () => {
    const free = await createProduct(site, { price: '0', stock: 5, name: 'Sticker' });
    const s = await shopperWithCart(free);

    const checkout = await s.checkout();

    assert.equal(checkout.status, 200, checkout.text);
    const placed = await order(checkout.json.order_id);
    assert.equal(placed.status, 'processing');
    assert.equal(placed.payment_method, '');
    assert.deepEqual(serviceCalls(), []);
  });

  /** The Payment Service changes the Eligible Methods and the site picks up the new status. */
  const eligible = async (methods) => {
    connection.service.setMethods(methods);
    await connection.refresh();
  };
  const offered = async (s) => (await s.cart()).json.payment_methods;

  for (const how of ['disconnected', 'revoked']) {
    test(`the gateway is refused at checkout once the site is ${how}`, async () => {
      connection.service.disconnect(how);
      await connection.refresh();
      const s = await shopperWithCart();

      assert.ok(!(await offered(s)).includes('gq_payments'));
      const checkout = await s.checkout({ payment: mbway });
      assert.equal(checkout.status, 400, checkout.text);
      assert.equal(checkout.json.code, 'woocommerce_rest_checkout_payment_method_disabled');
    });
  }

  test('the gateway is unavailable before the site was ever connected', async () => {
    await forgetConnection();
    try {
      const s = await shopperWithCart();

      assert.ok(!(await offered(s)).includes('gq_payments'));
      const checkout = await s.checkout({ payment: mbway });
      assert.equal(checkout.status, 400, checkout.text);
    } finally {
      connection = await connectSite(fake, { methods: [MBWAY, MULTIBANCO] });
    }
  });

  test('the gateway is unavailable while no method is eligible and switched on', async () => {
    await eligible([{ ...MBWAY, enabled: false }, { ...MULTIBANCO, status: 'unavailable' }]);
    const s = await shopperWithCart();

    assert.ok(!(await offered(s)).includes('gq_payments'));
    const checkout = await s.checkout({ payment: mbway });
    assert.equal(checkout.status, 400, checkout.text);
  });

  test('a method that is switched off is refused while another is offered', async () => {
    await eligible([{ ...MBWAY, enabled: false }, MULTIBANCO]);
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
  let comingSoon;
  before(async () => {
    blockCheckoutPage = await site.wpOk('option', 'get', 'woocommerce_checkout_page_id');
    // A (re)activated WooCommerce can put store pages in "coming soon" mode for visitors.
    comingSoon = (await site.wp('option', 'get', 'woocommerce_coming_soon')).stdout || 'no';
    await site.wpOk('option', 'update', 'woocommerce_coming_soon', 'no');
    // Cheque proves the pages do list available gateways.
    await site.wpOk('option', 'update', 'woocommerce_cheque_settings', '{"enabled":"yes","title":"Check payments"}', '--format=json');
  });
  after(async () => {
    await site.wpOk('option', 'update', 'woocommerce_checkout_page_id', blockCheckoutPage);
    await site.wpOk('option', 'update', 'woocommerce_coming_soon', comingSoon);
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
