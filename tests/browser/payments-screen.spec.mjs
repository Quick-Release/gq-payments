// Seam B: the GETQUICK → Payments admin screen.
import { expect, test } from '@playwright/test';
import { baseline, singleSite as site } from '../e2e/lib/site.mjs';
import { logIn } from './lib/login.mjs';

test.beforeEach(() => baseline());
test.afterAll(() => baseline());

const adminMenu = (page) => page.locator('#adminmenu');

test('sits under GETQUICK → Payments when gq-config provides the menu', async ({ page }) => {
  await page.goto('/wp-admin/');
  const getquick = adminMenu(page).locator('li#toplevel_page_getquick-options');

  await getquick.hover();
  await getquick.getByRole('link', { name: 'Payments', exact: true }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Payments' })).toBeVisible();
  await expect(page).toHaveURL(/admin\.php\?page=gq-payments/);
  await expect(adminMenu(page).locator('#menu-settings').getByRole('link', { name: 'Payments', exact: true })).toHaveCount(0);
});

test('falls back to Settings → Payments without the GETQUICK menu', async ({ page }) => {
  await site.wpOk('option', 'update', 'gq_test_gqconfig_menu', 'off');
  await page.goto('/wp-admin/');

  await expect(adminMenu(page).locator('li#toplevel_page_getquick-options')).toHaveCount(0);
  const settings = adminMenu(page).locator('#menu-settings');
  await settings.hover();
  await settings.getByRole('link', { name: 'Payments', exact: true }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Payments' })).toBeVisible();
});

test('loads its stylesheet only on the Payments screen', async ({ page }) => {
  const stylesheet = 'link#gq-payments-admin-css';

  await page.goto('/wp-admin/admin.php?page=gq-payments');
  await expect(page.locator(stylesheet)).toHaveCount(1);

  await page.goto('/wp-admin/');
  await expect(page.locator(stylesheet)).toHaveCount(0);
  await page.goto('/wp-admin/admin.php?page=wc-settings');
  await expect(page.locator(stylesheet)).toHaveCount(0);
});

test.describe('access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('a shop manager reaches the screen from the admin menu', async ({ page }) => {
    await logIn(page, 'shopmanager');

    await page.goto('/wp-admin/admin.php?page=gq-payments');

    await expect(page.getByRole('heading', { level: 1, name: 'Payments' })).toBeVisible();
    await expect(adminMenu(page).getByRole('link', { name: 'Payments', exact: true })).toBeVisible();
  });

  test('a subscriber is refused', async ({ page }) => {
    await logIn(page, 'subscriber');

    const response = await page.goto('/wp-admin/admin.php?page=gq-payments');

    expect(response.status()).toBe(403);
    await expect(page.getByRole('heading', { level: 1, name: 'Payments' })).toHaveCount(0);
  });
});

test.describe('when requirements are not met', () => {
  test.afterEach(() => baseline());

  test('explains why and does not register the screen', async ({ page }) => {
    await site.wpOk('plugin', 'deactivate', 'woocommerce');

    await page.goto('/wp-admin/plugins.php');

    await expect(page.locator('.notice-error', { hasText: 'GQ Payments' })).toHaveText(
      'GQ Payments is inactive because these requirements are not met: WooCommerce 10.9 or later must be active.'
    );
    await expect(adminMenu(page).getByRole('link', { name: 'Payments', exact: true })).toHaveCount(0);
  });
});
