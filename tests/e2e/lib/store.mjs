// A shopper using the WooCommerce Store API (the headless storefront's checkout path).
import { singleSite } from './site.mjs';

const address = {
  first_name: 'Ana',
  last_name: 'Silva',
  address_1: 'Rua Augusta 1',
  city: 'Lisboa',
  postcode: '1100-048',
  country: 'PT',
  email: 'ana@example.test',
  phone: '+351912345678',
};

/**
 * Starts a fresh Store API session (its own Cart-Token).
 * Resolves { addItem(id, quantity), checkout(body), request(method, path, json) }.
 */
export async function shopper(target = singleSite) {
  let token = null;

  async function request(method, path, json) {
    const response = await fetch(`${target.url}/wp-json/wc/store/v1${path}`, {
      method,
      headers: { ...(token ? { 'cart-token': token } : {}), ...(json ? { 'content-type': 'application/json' } : {}) },
      body: json ? JSON.stringify(json) : undefined,
    });
    token = response.headers.get('cart-token') ?? token;
    const text = await response.text();
    let body = null;
    try {
      body = JSON.parse(text);
    } catch {}
    return { status: response.status, json: body, text };
  }

  await request('GET', '/cart');

  return {
    request,
    cart: () => request('GET', '/cart'),
    addItem: (id, quantity = 1) => request('POST', '/cart/add-item', { id: Number(id), quantity }),
    /** POST /checkout; `payment` is { method, data: { key: value } } or omitted for no payment. */
    checkout: ({ payment } = {}) =>
      request('POST', '/checkout', {
        billing_address: address,
        shipping_address: address,
        ...(payment
          ? {
              payment_method: payment.method,
              payment_data: Object.entries(payment.data ?? {}).map(([key, value]) => ({ key, value })),
            }
          : {}),
      }),
  };
}

/** Creates a simple product with managed stock and resolves its ID. */
export async function createProduct(target = singleSite, { price = '10.00', stock = 5, name = 'Shirt' } = {}) {
  return target.wpOk(
    'wc',
    'product',
    'create',
    '--user=admin',
    `--name=${name}`,
    `--regular_price=${price}`,
    '--manage_stock=true',
    `--stock_quantity=${stock}`,
    '--porcelain'
  );
}
