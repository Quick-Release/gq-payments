// Seam A: Connection Request, connection health and Eligible Methods, against the fake Payment Service.
// The administrator drives GETQUICK → Payments over HTTP (logged-in admin-post forms); the Operator's
// side is tests/e2e/lib/payment-service.mjs.
import assert from 'node:assert/strict';
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
  for (const option of ['gq_payments_site_id', 'gq_payments_site_keys', 'gq_payments_connection', 'gq_payments_environment', 'gq_test_site_key_constant']) {
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

/** Logged-in admin driving the Payments screen. */
function adminSession(get) {
  const path = '/wp-admin/admin.php?page=gq-payments';
  async function html() {
    const page = await get(path);
    assert.equal(page.status, 200, page.html.slice(0, 500));
    return page.html;
  }
  return {
    html,
    async text() {
      return toText(await html());
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

