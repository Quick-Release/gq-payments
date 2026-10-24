// Helpers for driving a wp-env site from tests: WP-CLI and logged-in admin requests.
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const root = fileURLToPath(new URL('../../..', import.meta.url));
const wpEnv = `${root}node_modules/.bin/wp-env`;

/**
 * A disposable wp-env site.
 *
 * @param {object} options
 * @param {string} [options.config] Path to a .wp-env.json other than the repo root's.
 * @param {string} options.url      Site URL, e.g. http://localhost:8988.
 */
export function site({ config, url }) {
  const configArgs = config ? ['--config', config] : [];

  /**
   * Runs WP-CLI in the site's cli container.
   * Resolves { ok, stdout, stderr }; never throws on a non-zero exit so tests can assert failures.
   */
  async function wp(...args) {
    try {
      const { stdout, stderr } = await run(wpEnv, [...configArgs, 'run', 'cli', '--', 'wp', ...args], {
        cwd: root,
        maxBuffer: 10 * 1024 * 1024,
      });
      return { ok: true, stdout: stdout.trim(), stderr: stderr.trim() };
    } catch (error) {
      return { ok: false, stdout: (error.stdout ?? '').trim(), stderr: (error.stderr ?? '').trim() };
    }
  }

  /** Runs WP-CLI and throws if it fails. */
  async function wpOk(...args) {
    const result = await wp(...args);
    if (!result.ok) {
      throw new Error(`wp ${args.join(' ')} failed:\n${result.stderr || result.stdout}`);
    }
    return result.stdout;
  }

  /** Logs in through wp-login.php and returns a fetch for admin pages carrying the session cookies. */
  async function loginAs(username = 'admin', password = 'password') {
    const jar = new Map([['wordpress_test_cookie', 'WP%20Cookie%20check']]);
    const cookieHeader = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
    const remember = (response) => {
      for (const cookie of response.headers.getSetCookie()) {
        const [pair] = cookie.split(';');
        const index = pair.indexOf('=');
        jar.set(pair.slice(0, index), pair.slice(index + 1));
      }
    };

    const response = await fetch(`${url}/wp-login.php`, {
      method: 'POST',
      redirect: 'manual',
      headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: cookieHeader() },
      body: new URLSearchParams({ log: username, pwd: password, testcookie: '1', redirect_to: `${url}/wp-admin/` }),
    });
    remember(response);
    if (response.status !== 302) {
      throw new Error(`Login as ${username} failed with HTTP ${response.status}`);
    }

    return async function get(path, { headers = {} } = {}) {
      const page = await fetch(`${url}${path}`, { redirect: 'manual', headers: { ...headers, cookie: cookieHeader() } });
      return { status: page.status, html: await page.text() };
    };
  }

  return { url, wp, wpOk, loginAs };
}

export const singleSite = site({ url: process.env.WP_BASE_URL ?? 'http://localhost:8988' });

export const multisite = site({
  config: 'tests/e2e/multisite/.wp-env.json',
  url: process.env.WP_MULTISITE_URL ?? 'http://localhost:8990',
});

/** Puts the single site in the baseline state: dependencies, GQ Payments and the GETQUICK menu all active. */
export async function baseline(target = singleSite) {
  await target.wpOk('plugin', 'activate', 'woocommerce', 'getquick-ecommerce', 'gq-payments');
  await target.wp('option', 'delete', 'gq_test_gqconfig_menu');
}
