// Seam B: connecting the site, health and Eligible Methods on GETQUICK → Payments, against the fake Payment Service.
import { expect, test } from '@playwright/test';
import { startFakeService } from '../e2e/fake-service/fake-service.mjs';
import { paymentService } from '../e2e/lib/payment-service.mjs';
import { baseline, singleSite as site } from '../e2e/lib/site.mjs';

let fake;
let service;

async function resetConnection() {
  for (const option of ['gq_payments_site_id', 'gq_payments_site_keys', 'gq_payments_connection', 'gq_payments_environment', 'gq_test_site_key_constant']) {
    await site.wp('option', 'delete', option);
  }
  await site.wp('transient', 'delete', 'gq_payments_connection_fresh');
}

test.beforeAll(async () => {
  fake = await startFakeService();
  await baseline();
});
test.beforeEach(async () => {
  fake.reset();
  await resetConnection();
  service = paymentService(fake, {
    environment: 'sandbox',
    methods: [
      { id: 'mbway', label: 'MB WAY', status: 'eligible', enabled: true },
      { id: 'card', label: 'Card', status: 'unavailable', enabled: false },
    ],
  });
});
test.afterAll(async () => {
  await resetConnection();
  await fake?.close();
});

const region = (page, name) => page.getByRole('region', { name, exact: true });

test('an administrator connects the site and manages Eligible Methods', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=gq-payments');
  await expect(region(page, 'Connection')).toContainText('Not connected');

  await page.getByRole('button', { name: 'Create Connection Request' }).click();

  await expect(page.getByText('Connection Request sent.')).toBeVisible();
  await expect(region(page, 'Connection')).toContainText('Awaiting approval');
  const request = region(page, 'Connection Request');
  await expect(request).toContainText(service.latest().fingerprint);
  await expect(request).toContainText(service.latest().publicKey);
  await expect(request).toContainText(site.url);

  service.approve();
  await page.getByRole('button', { name: 'Refresh status' }).click();

  await expect(region(page, 'Connection')).toContainText('Connected');
  await expect(region(page, 'Connection')).toContainText('Environment: Sandbox (set by GETQUICK)');
  const methods = region(page, 'Eligible Methods');
  await expect(methods.getByRole('row', { name: /MB WAY/ })).toContainText('On');
  await expect(methods.getByRole('row', { name: /Card/ })).toContainText('Unavailable');
  await expect(methods.getByRole('button', { name: /Card/ })).toHaveCount(0);

  await methods.getByRole('button', { name: 'Turn off MB WAY' }).click();

  await expect(page.getByText('Payment method updated.')).toBeVisible();
  await expect(region(page, 'Eligible Methods').getByRole('row', { name: /MB WAY/ })).toContainText('Off');
  expect(service.methods.find((m) => m.id === 'mbway').enabled).toBe(false);
  await expect(page.getByRole('button', { name: 'Create replacement Connection Request' })).toBeVisible();
  await expect(page.getByRole('button', { name: /disconnect|delete/i })).toHaveCount(0);
});

test('a disconnected site says so and how to recover', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=gq-payments');
  await page.getByRole('button', { name: 'Create Connection Request' }).click();
  service.approve();
  service.disconnect('revoked');

  await page.getByRole('button', { name: 'Refresh status' }).click();

  await expect(region(page, 'Connection')).toContainText('Disconnected');
  await expect(region(page, 'Connection')).toContainText('New checkouts cannot take payment; payments already started are still recorded.');
});

test('an unreachable service is reported without internal details', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=gq-payments');
  await page.getByRole('button', { name: 'Create Connection Request' }).click();
  service.setDown(true);

  await page.getByRole('button', { name: 'Refresh status' }).click();

  await expect(region(page, 'Connection')).toContainText('Unreachable');
  await expect(page.locator('body')).not.toContainText('password');
});

test('warns when the Site Key is stored in the database', async ({ page }) => {
  await site.wpOk('option', 'update', 'gq_test_site_key_constant', 'off');

  await page.goto('/wp-admin/admin.php?page=gq-payments');

  await expect(page.locator('.notice-warning')).toContainText('The Site Key is stored in the WordPress database, which is the weaker choice');
});

test('links to Banquinha without credentials', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=gq-payments');

  const link = page.getByRole('link', { name: 'Manage payments and refunds in Banquinha' });
  await expect(link).toHaveAttribute('href', 'https://my.banquinha.pt/');
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
});
