import { expect } from '@playwright/test';

export async function logIn(page, username, password = 'password') {
  await page.goto('/wp-login.php');
  await page.getByLabel('Username or Email Address').fill(username);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Log In' }).click();
  // WooCommerce sends customers (e.g. subscribers) to My account instead of wp-admin.
  await expect(page).not.toHaveURL(/wp-login\.php/);
}
