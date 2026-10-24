// Seam A: Connection Request, connection health and Eligible Methods, against the fake Payment Service.
// The administrator drives GETQUICK → Payments over HTTP (logged-in admin-post forms); the Operator's
// side is tests/e2e/lib/payment-service.mjs.
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { after, afterEach, before, beforeEach, describe, test } from 'node:test';
import { startFakeService, vectors } from './fake-service/fake-service.mjs';
import { DOWN, paymentService } from './lib/payment-service.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';

const FIXTURE_FINGERPRINT = 'SHA256:10ba:682c:8ad1:3513'; // of vectors.site_key.public_key
const MBWAY = { id: 'mbway', label: 'MB WAY', status: 'eligible', enabled: true };
const MULTIBANCO = { id: 'multibanco', label: 'Multibanco', status: 'eligible', enabled: false };
const CARD = { id: 'card', label: 'Card', status: 'unavailable', enabled: false };

let fake;
let service;
let admin;

async function resetConnection() {
  for (const option of ['gq_payments_site_id', 'gq_payments_site_keys', 'gq_payments_connection', 'gq_payments_environment', 'gq_test_site_key_constant', 'gq_test_site_key', 'gq_test_site_key_next']) {
    await site.wp('option', 'delete', option);
  }
  await site.wp('transient', 'delete', 'gq_payments_connection_fresh');
}

before(async () => {
  fake = await startFakeService();
  await baseline();
  admin = adminSession(await site.loginAs('admin'));
});
beforeEach(async () => {
  await resetConnection();
  service = paymentService(fake, { methods: [MBWAY, MULTIBANCO, CARD] });
});
afterEach(() => fake.reset());
after(async () => {
  await resetConnection();
  await fake?.close();
});

describe('Connection Request', () => {
  test('sends the site URL, public key and fingerprint and shows them for the Operator', async () => {
    const result = await admin.submit('gq_payments_connection_request');

    assert.equal(result.status, 302);
    assert.equal(service.enrollments.length, 1);
    const [enrollment] = service.enrollments;
    assert.equal(enrollment.publicKey, vectors.site_key.public_key);
    assert.equal(enrollment.fingerprint, FIXTURE_FINGERPRINT);
    assert.equal(enrollment.siteUrl, site.url);
    assert.match(enrollment.siteId, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);

    const page = await admin.text();
    assert.match(page, /Awaiting approval/);
    assert.ok(page.includes(vectors.site_key.public_key), page);
    assert.ok(page.includes(FIXTURE_FINGERPRINT), page);
    assert.ok(page.includes(site.url), page);
  });
});

describe('approval and connection health', () => {
  test('once the Operator approves, the screen shows connected, the environment GETQUICK reported and the methods', async () => {
    service.setEnvironment('production');
    await admin.submit('gq_payments_connection_request');
    service.approve();

    await admin.submit('gq_payments_refresh_status');

    const page = await admin.text();
    assert.match(page, /Connected/);
    assert.match(page, /Environment: Production \(set by GETQUICK\)/);
    assert.match(page, /MB WAY On/);
    assert.match(page, /Multibanco Off/);
    assert.match(page, /Card Unavailable/);
    assert.equal(await site.wpOk('option', 'get', 'gq_payments_environment'), 'production');
    const statusCall = fake.calls.findLast((call) => call.path === '/v1/site/status');
    assert.equal(statusCall.verified, true);
    assert.equal(statusCall.keyId, service.latest().keyId);
  });

  test('health is cached briefly instead of calling the service on every page view', async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');
    const before = fake.calls.length;

    await admin.html();
    await admin.html();

    assert.equal(fake.calls.length, before);
  });

  test('a site with no eligible method yet still connects', async () => {
    service.setMethods([]);
    await admin.submit('gq_payments_connection_request');
    service.approve();

    await admin.submit('gq_payments_refresh_status');

    const page = await admin.text();
    assert.match(page, /Connected/);
    assert.match(page, /No payment methods are eligible yet/);
  });

  test('an unreachable service is shown as unreachable with masked errors, keeping the last reported methods', async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');
    service.setDown(true);

    await admin.submit('gq_payments_refresh_status');

    const page = await admin.text();
    assert.match(page, /Unreachable/);
    assert.match(page, /MB WAY On/);
    assert.doesNotMatch(page, /password|10\.1\.2\.3|pg:|503/);
  });

  test('a Connection Request while the service is unreachable fails with a masked error and keeps no key', async () => {
    service.setDown(true);

    const result = await admin.submit('gq_payments_connection_request');
    const page = await admin.follow(result);

    assert.match(page, /The Payment Service could not be reached/);
    assert.doesNotMatch(page, /password|10\.1\.2\.3/);
    assert.match(page, /Not connected/);
  });
});

describe('replacement Connection Request', () => {
  test('replaces the active key only after the service confirms the new enrollment', async () => {
    await site.wpOk('option', 'update', 'gq_test_site_key_constant', 'off');
    await admin.submit('gq_payments_connection_request');
    const first = service.latest();
    service.approve(first);
    await admin.submit('gq_payments_refresh_status');

    await admin.submit('gq_payments_connection_request');
    const second = service.latest();
    assert.notEqual(second.publicKey, first.publicKey);

    let page = await admin.text();
    assert.match(page, /Connected/);
    assert.match(page, /Replacement awaiting approval/);
    assert.ok(page.includes(second.fingerprint), 'shows the replacement fingerprint');
    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');
    assert.ok(fake.calls.some((c) => c.path === '/v1/site/status' && c.keyId === first.keyId && c.verified), 'old key still in use');

    service.approve(second);
    await admin.submit('gq_payments_refresh_status');
    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');

    page = await admin.text();
    assert.match(page, /Connected/);
    assert.doesNotMatch(page, /Replacement awaiting approval/);
    assert.deepEqual(
      fake.calls.filter((c) => c.path === '/v1/site/status').map((c) => [c.keyId, c.verified]),
      [[second.keyId, true]]
    );
  });

  test('with GQ_PAYMENTS_SITE_KEY_NEXT set, sends the next key and switches to it only after approval, writing no secret', async () => {
    const next = newSiteKey();
    await admin.submit('gq_payments_connection_request');
    const first = service.latest();
    assert.equal(first.publicKey, vectors.site_key.public_key);
    service.approve(first);
    await admin.submit('gq_payments_refresh_status');
    await site.wpOk('option', 'update', 'gq_test_site_key_next', next.secret);

    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');
    assert.ok(fake.calls.every((c) => c.keyId === first.keyId && c.verified), 'defining the next key changes nothing before a Connection Request');

    await admin.submit('gq_payments_connection_request');
    const second = service.latest();
    assert.equal(second.publicKey, next.publicKey);

    let page = await admin.text();
    assert.match(page, /Connected/);
    assert.match(page, /Replacement awaiting approval/);
    assert.ok(page.includes(second.fingerprint), 'shows the replacement fingerprint');
    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');
    assert.ok(fake.calls.some((c) => c.path === '/v1/site/status' && c.keyId === first.keyId && c.verified), 'current key still in use');

    service.approve(second);
    await admin.submit('gq_payments_refresh_status');
    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');

    page = await admin.text();
    assert.match(page, /Connected/);
    assert.doesNotMatch(page, /Replacement awaiting approval/);
    assert.deepEqual(
      fake.calls.filter((c) => c.path === '/v1/site/status').map((c) => [c.keyId, c.verified]),
      [[second.keyId, true]]
    );

    const options = await site.wpOk('db', 'query', 'SELECT option_name, option_value FROM wp_options', '--skip-column-names');
    const html = await admin.html();
    for (const secret of [vectors.site_key.secret_key, next.secret]) {
      const seed = Buffer.from(secret, 'base64').subarray(0, 32).toString('base64');
      for (const [where, text] of Object.entries({ options: testOptionsOmitted(options), html })) {
        assert.ok(!text.includes(secret) && !text.includes(seed), `a Site Key secret found in ${where}`);
      }
    }
  });

  test('with GQ_PAYMENTS_SITE_KEY managing the key and no next key, a replacement is refused with guidance', async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');

    const result = await admin.submit('gq_payments_connection_request');

    assert.match(await admin.follow(result), /set GQ_PAYMENTS_SITE_KEY_NEXT/);
    assert.equal(service.enrollments.length, 1);
  });

  test('changing GQ_PAYMENTS_SITE_KEY never makes the new key active before approval', async () => {
    await admin.submit('gq_payments_connection_request');
    const first = service.latest();
    service.approve(first);
    await admin.submit('gq_payments_refresh_status');
    const changed = newSiteKey();
    await site.wpOk('option', 'update', 'gq_test_site_key', changed.secret);
    // Even if the service trusted the new key, the site must not use it under the enrolled key ID.
    fake.trustSiteKeys([first.publicKey, changed.publicKey]);

    fake.calls.length = 0;
    await admin.submit('gq_payments_refresh_status');

    assert.ok(!fake.calls.some((c) => c.keyId === first.keyId), JSON.stringify(fake.calls.map((c) => [c.path, c.keyId])));
    const page = await admin.text();
    assert.doesNotMatch(page, /\bConnected\b/);
    assert.match(page, /The Site Key enrolled with GETQUICK is no longer configured/);

    // A Connection Request for the new key is pending until an Operator approves it.
    await admin.submit('gq_payments_connection_request');
    const second = service.latest();
    assert.equal(second.publicKey, changed.publicKey);
    assert.match(await admin.text(), /Awaiting approval/);
    service.approve(second);
    await admin.submit('gq_payments_refresh_status');
    assert.match(await admin.text(), /\bConnected\b/);
    assert.doesNotMatch(await admin.text(), /no longer configured/);
  });

  test('the screen offers no way to delete the Site Key or disconnect', async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');

    const html = await admin.html();
    const actions = [...html.matchAll(/name="action" value="([^"]+)"/g)].map((m) => m[1]);
    assert.deepEqual([...new Set(actions)].sort(), ['gq_payments_connection_request', 'gq_payments_refresh_status', 'gq_payments_set_method']);
    assert.doesNotMatch(toText(html), /disconnect\b(?! this)|delete/i);
  });
});

describe('disconnected or revoked', () => {
  for (const how of ['disconnected', 'revoked']) {
    test(`a ${how} site shows disconnected, but signed Payment Service requests are still accepted`, async () => {
      await admin.submit('gq_payments_connection_request');
      service.approve();
      await admin.submit('gq_payments_refresh_status');
      service.disconnect(how);

      await admin.submit('gq_payments_refresh_status');

      assert.match(await admin.text(), /Disconnected/);
      const siteId = await site.wpOk('option', 'get', 'gq_payments_site_id');
      const inbound = await fake.callSite({ method: 'POST', route: '/gq-test/v1/ping', json: { version: 3 }, siteId });
      assert.equal(inbound.status, 200, inbound.text);
    });
  }
});

describe('Eligible Methods toggles', () => {
  beforeEach(async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');
  });

  test('switching a method off in WordPress is written to the Payment Service, which owns it', async () => {
    fake.calls.length = 0;

    const result = await admin.submit('gq_payments_set_method', { method: 'mbway' });

    const [call] = fake.calls.filter((c) => c.path === '/v1/site/configuration');
    assert.equal(call.verified, true);
    assert.deepEqual(call.json, { methods: { mbway: false } });
    assert.equal(service.methods.find((m) => m.id === 'mbway').enabled, false);
    const page = await admin.follow(result);
    assert.match(page, /MB WAY Off/);
    assert.match(page, /Payment method updated/);
  });

  test('switching a method on in WordPress', async () => {
    await admin.submit('gq_payments_set_method', { method: 'multibanco' });

    assert.equal(service.methods.find((m) => m.id === 'multibanco').enabled, true);
    assert.match(await admin.text(), /Multibanco On/);
  });

  test('a toggle the service refuses leaves the cached methods unchanged', async () => {
    const result = await admin.submit('gq_payments_set_method', { method: 'mbway' }, { method: 'card', enabled: '1' });

    const page = await admin.follow(result);
    assert.match(page, /The Payment Service refused the request/);
    assert.match(page, /Card Unavailable/);
  });

  test('changes made on the Payment Service side show after the cache refreshes', async () => {
    service.setMethods([{ ...MBWAY, enabled: false }, { ...CARD, status: 'eligible', enabled: true }]);

    assert.match(await admin.text(), /MB WAY On/, 'cached copy until refreshed');
    await admin.submit('gq_payments_refresh_status');

    const page = await admin.text();
    assert.match(page, /MB WAY Off/);
    assert.match(page, /Card On/);
    assert.doesNotMatch(page, /Multibanco/);
  });
});

describe('admin writes', () => {
  test('are refused without the action nonce', async () => {
    const result = await admin.submit('gq_payments_connection_request', {}, { _wpnonce: 'forged' });

    assert.equal(result.status, 403);
    assert.equal(service.enrollments.length, 0);
  });

  test('are refused without the manage_woocommerce capability', async () => {
    await site.wp('user', 'create', 'subscriber3', 'subscriber3@example.test', '--role=subscriber', '--user_pass=password');
    const subscriber = await site.loginAs('subscriber3');
    const fields = findForm(await admin.html(), { action: 'gq_payments_connection_request' });

    const result = await subscriber('/wp-admin/admin-post.php', { form: fields });

    assert.equal(result.status, 403);
    assert.equal(service.enrollments.length, 0);
  });
});

describe('Site Key custody', () => {
  test('without GQ_PAYMENTS_SITE_KEY, a key is generated into an option with autoload off and the screen warns', async () => {
    await site.wpOk('option', 'update', 'gq_test_site_key_constant', 'off');

    await admin.submit('gq_payments_connection_request');

    assert.notEqual(service.latest().publicKey, vectors.site_key.public_key);
    const autoload = await site.wpOk('db', 'query', "SELECT autoload FROM wp_options WHERE option_name = 'gq_payments_site_keys'", '--skip-column-names');
    assert.match(autoload, /^(no|off)$/);
    assert.match(await admin.text(), /The Site Key is stored in the WordPress database, which is the weaker choice/);
  });

  test('with GQ_PAYMENTS_SITE_KEY set, the Site Key appears in no option, log or REST response', async () => {
    await admin.submit('gq_payments_connection_request');
    service.approve();
    await admin.submit('gq_payments_refresh_status');
    await admin.submit('gq_payments_set_method', { method: 'mbway' });

    const secret = vectors.site_key.secret_key;
    const seed = Buffer.from(secret, 'base64').subarray(0, 32).toString('base64');
    const options = await site.wpOk('db', 'query', 'SELECT option_name, option_value FROM wp_options', '--skip-column-names');
    const logs = await site.wp('eval', "echo file_exists( WP_CONTENT_DIR . '/debug.log' ) ? file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';");
    const rest = await Promise.all(['/wp-json/', '/wp-json/wp/v2/settings'].map((path) => fetch(`${site.url}${path}`).then((r) => r.text())));
    const page = await admin.html();

    assert.match(options, /gq_payments_site_keys/);
    for (const [where, text] of Object.entries({ options, logs: logs.stdout, rest: rest.join('\n'), page })) {
      assert.ok(!text.includes(secret) && !text.includes(seed), `Site Key found in ${where}`);
    }
    assert.doesNotMatch(await admin.text(), /stored in the WordPress database/);
  });
});

describe('Banquinha', () => {
  test('the link carries no credentials and no claimed identity', async () => {
    const html = await admin.html();
    const link = /<a href="([^"]*)"[^>]*>Manage payments and refunds in Banquinha/.exec(html);

    assert.ok(link, 'Banquinha link present');
    const url = new URL(decode(link[1]));
    assert.equal(url.protocol, 'https:');
    assert.equal(url.search + url.hash + url.username + url.password, '');
    assert.match(link[0], /rel="noopener noreferrer"/);
  });
});

/** The options dump without the test-only options that feed the constants. */
const testOptionsOmitted = (options) => options.split('\n').filter((line) => !line.startsWith('gq_test_site_key')).join('\n');

/** A fresh Ed25519 Site Key: base64 64-byte secret (seed ‖ public key) and base64 public key. */
function newSiteKey() {
  const jwk = generateKeyPairSync('ed25519').privateKey.export({ format: 'jwk' });
  const seed = Buffer.from(jwk.d, 'base64url');
  const publicKey = Buffer.from(jwk.x, 'base64url');
  return { secret: Buffer.concat([seed, publicKey]).toString('base64'), publicKey: publicKey.toString('base64') };
}

/** Logged-in admin driving the Payments screen. */
function adminSession(get) {
  const path = '/wp-admin/admin.php?page=gq-payments';
  async function html() {
    const page = await get(path);
    assert.equal(page.status, 200, page.html.slice(0, 500));
    return page.html;
  }
  return {
    get,
    html,
    async text() {
      return toText(await html());
    },
    /** Text of the page an admin-post redirect leads to (with its notice). */
    async follow(result) {
      assert.equal(result.status, 302, result.html.slice(0, 500));
      const page = await get(result.location.replace(site.url, ''));
      return toText(page.html);
    },
    /** Submits the screen's form for ACTION (matching MATCH fields), overriding OVERRIDES. */
    async submit(action, match = {}, overrides = {}) {
      const fields = findForm(await html(), { action, ...match });
      assert.ok(fields, `no form for ${action} ${JSON.stringify(match)}`);
      return get('/wp-admin/admin-post.php', { form: { ...fields, ...overrides } });
    },
  };
}

function findForm(html, match) {
  for (const [, form] of html.matchAll(/<form\b[^>]*>([\s\S]*?)<\/form>/g)) {
    const fields = {};
    for (const [input] of form.matchAll(/<(?:input|button)\b[^>]*>/g)) {
      const name = /\bname="([^"]*)"/.exec(input)?.[1];
      const value = /\bvalue="([^"]*)"/.exec(input)?.[1] ?? '';
      if (name) fields[name] = decode(value);
    }
    if (Object.entries(match).every(([k, v]) => fields[k] === v)) return fields;
  }
  return null;
}

const decode = (s) =>
  s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#0?43;/g, '+').replace(/&#x2F;/gi, '/').replace(/&amp;/g, '&');

const toText = (html) =>
  decode(
    html
      .replace(/<(script|style)\b[\s\S]*?<\/\1>/g, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
  );

