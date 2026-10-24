// Selects the WooCommerce order storage for the single site and fails unless it took effect.
// Usage: node tests/e2e/select-storage.mjs <legacy|hpos>
import { singleSite as site } from './lib/site.mjs';

const storage = process.argv[2];
if (storage !== 'legacy' && storage !== 'hpos') {
  console.error('Usage: node tests/e2e/select-storage.mjs <legacy|hpos>');
  process.exit(2);
}

const current = () =>
  site.wpOk('eval', "echo \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';");

try {
  // A fresh WooCommerce defers part of its install to the first wp-admin page load
  // (admin_init → woocommerce_newly_installed), and that step turns HPOS on for a shop with no
  // orders. WP-CLI never fires admin_init, so without this the first admin request a test makes
  // would silently override the storage selected here. Run that step now; it runs only once.
  await site.wpOk('eval', 'WC_Install::newly_installed();');

  await site.wpOk('wc', 'hpos', 'sync');
  console.log(await site.wpOk('wc', 'hpos', storage === 'hpos' ? 'enable' : 'disable'));

  const actual = await current();
  if (actual !== storage) {
    throw new Error(`asked for ${storage} order storage, but WooCommerce is using ${actual}`);
  }
  console.log(`WooCommerce order storage: ${actual}`);
} catch (error) {
  console.error(`Selecting ${storage} order storage failed: ${error.message}`);
  process.exit(1);
}
