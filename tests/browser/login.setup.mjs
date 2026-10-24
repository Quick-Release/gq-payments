import { test as setup } from '@playwright/test';
import { baseline, singleSite } from '../e2e/lib/site.mjs';
import { logIn } from './lib/login.mjs';

setup('baseline site and admin session', async ({ page }) => {
  await baseline();
  // A shop manager has manage_woocommerce but not manage_options.
  await singleSite.wp('user', 'create', 'shopmanager', 'shopmanager@example.test', '--role=shop_manager', '--user_pass=password');
  await singleSite.wp('user', 'create', 'subscriber', 'subscriber@example.test', '--role=subscriber', '--user_pass=password');

  await logIn(page, 'admin');
  await page.context().storageState({ path: '.playwright/admin.json' });
});
