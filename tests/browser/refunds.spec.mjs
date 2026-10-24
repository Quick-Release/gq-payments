// Seam B: on a gq_payments order's edit screen, WooCommerce's refund UI is replaced by a pointer
// to Banquinha, and its refund request is refused even when sent directly.
import { expect, test } from '@playwright/test';
import { startFakeService } from '../e2e/fake-service/fake-service.mjs';
import { connectSite, forgetConnection } from '../e2e/lib/connect.mjs';
import { paymentOrders } from '../e2e/lib/orders.mjs';
import { baseline, singleSite as site } from '../e2e/lib/site.mjs';

let fake;
let orders;

test.beforeAll(async () => {
  fake = await startFakeService();
  await baseline();
  await site.wpOk('option', 'update', 'woocommerce_currency', 'EUR');
  orders = paymentOrders(fake, await connectSite(fake));
});
test.afterAll(async () => {
  await forgetConnection();
  await fake?.close();
});

/** A €20.00 order the Payment Service reported paid. */
async function paidOrder(state = {}) {
  const order = await orders.startedOrder();
  const paid = await orders.sendState(order, { version: 1, state: 'paid', attempt_id: 'att_paid', ...state });
  expect(paid.status, paid.text).toBe(200);
  return order;
}

/** The order's admin edit screen (HPOS or legacy, whichever is active), as a path. */
const editScreen = async ({ id }) => {
  const url = new URL(await site.wpOk('eval', `echo wc_get_order( ${Number(id)} )->get_edit_order_url();`));
  return url.pathname + url.search;
};
const itemsBox = (page) => page.locator('#woocommerce-order-items');

test('a paid order offers no WooCommerce refund, and points to Banquinha', async ({ page }) => {
  const order = await paidOrder();

  await page.goto(await editScreen(order));

  const items = itemsBox(page);
  await expect(items).toContainText('Refunds for this order are made in Banquinha.');
  await expect(items.getByRole('link', { name: 'Open Banquinha' })).toHaveAttribute('href', 'https://my.banquinha.pt/');
  await expect(items.getByRole('button', { name: 'Refund', exact: true })).toHaveCount(0);
  await expect(page.locator('.refund-actions, .do-api-refund, .do-manual-refund')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /^Refund .* via / })).toHaveCount(0);
});

test("WooCommerce's refund request is refused when sent from the order screen anyway", async ({ page }) => {
  const order = await paidOrder();
  await page.goto(await editScreen(order));
  const nonce = await page.evaluate(() => window.woocommerce_admin_meta_boxes.order_item_nonce);

  const response = await page.request.post('/wp-admin/admin-ajax.php', {
    form: {
      action: 'woocommerce_refund_line_items',
      security: nonce,
      order_id: String(order.id),
      refund_amount: '5.00',
      refunded_amount: '0',
      refund_reason: '',
      line_item_qtys: '{}',
      line_item_totals: '{}',
      line_item_tax_totals: '{}',
      api_refund: 'false',
      restock_refunded_items: 'false',
    },
  });

  const body = await response.json();
  expect(body.success).toBe(false);
  expect(body.data.error).toContain('Refunds for this order are made in Banquinha.');
  await page.reload();
  await expect(itemsBox(page).locator('tr.refund')).toHaveCount(0);
});

test('a verified refund is listed on the order, still without a refund button', async ({ page }) => {
  const order = await paidOrder();
  const refunded = await orders.sendState(order, { version: 2, state: 'paid', attempt_id: 'att_paid', refunded_total: '5.00' });
  expect(refunded.status, refunded.text).toBe(200);

  await page.goto(await editScreen(order));

  const items = itemsBox(page);
  await expect(items.locator('tr.refund')).toHaveCount(1);
  await expect(items.locator('tr.refund')).toContainText('Refund made in Banquinha.');
  await expect(items.getByRole('button', { name: 'Refund', exact: true })).toHaveCount(0);
  await expect(items).toContainText('Refunds for this order are made in Banquinha.');
});
