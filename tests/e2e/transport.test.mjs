// Seam A: signed transport between the site and the fake Payment Service.
// The gq-test/v1 routes in tests/e2e/mu-plugins/gq-test-env.php stand in for the payment routes
// later slices add: they are guarded by Transport::authorize and relay calls through Transport::client.
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService, TEST_SITE_ID, vectors } from './fake-service/fake-service.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';

let fake;

before(async () => {
  fake = await startFakeService();
  await baseline();
  await site.wpOk('option', 'update', 'gq_payments_site_id', TEST_SITE_ID);
  await site.wp('option', 'delete', 'gq_payments_environment');
});
afterEach(() => fake.reset());
after(async () => {
  await site.wp('option', 'delete', 'gq_payments_site_id');
  await site.wp('option', 'delete', 'gq_payments_environment');
  await fake?.close();
});

const ping = { method: 'GET', route: '/gq-test/v1/ping', query: { order: '123', key: 'wc_order_AbC123' } };
const unauthorized = { code: 'gq_payments_unauthorized', message: 'Request not authorized.', data: { status: 401 } };

describe('inbound requests from the Payment Service', () => {
  test('a request signed with the current Service Key is accepted', async () => {
    const response = await fake.callSite(ping);

    assert.equal(response.status, 200, response.text);
    assert.deepEqual(response.json, { verified: true });
  });

  test('the signature covers the REST route however the request reaches WordPress', async () => {
    const response = await fake.callSite({ ...ping, form: 'rest_route' });

    assert.equal(response.status, 200, response.text);
  });

  test('a request signed with the next Service Key is accepted (rotation)', async () => {
    const response = await fake.callSite({ ...ping, method: 'POST', json: { version: 1 }, keyId: 'test-sandbox-next' });

    assert.equal(response.status, 200, response.text);
  });

  // Exact ±300/301 s edges are pinned in tests/php (VerifierTest); over HTTP, whole-second
  // clocks on each side can shift the difference by a second, so use a clear margin here.
  const now = () => Math.floor(Date.now() / 1000);
  const rejected = {
    'an unsigned request': () => fetch(`${site.url}/wp-json/gq-test/v1/ping`).then(toResult),
    'an unknown key ID': () => fake.callSite({ ...ping, keyId: 'unknown', secretKey: vectors.service_keys['test-sandbox-current'].secret_key }),
    'a signature by a key the site does not trust': () => fake.callSite({ ...ping, secretKey: vectors.stranger_key.secret_key }),
    'a tampered body': () => {
      const request = fake.signRequest({ ...ping, method: 'POST', json: { state: 'pending' } });
      return fake.send({ ...request, body: JSON.stringify({ state: 'paid' }) });
    },
    'a tampered query': () => {
      const request = fake.signRequest(ping);
      return fake.send({ ...request, url: request.url.replace('order=123', 'order=124') });
    },
    'a timestamp older than five minutes': () => fake.callSite({ ...ping, timestamp: now() - 330 }),
    'a timestamp more than five minutes ahead': () => fake.callSite({ ...ping, timestamp: now() + 330 }),
    'a request for another site': () => fake.callSite({ ...ping, siteId: '00000000-0000-4000-8000-000000000000' }),
  };
  for (const [name, attempt] of Object.entries(rejected)) {
    test(`${name} fails closed with a generic error`, async () => {
      const response = await attempt();

      assert.equal(response.status, 401, response.text);
      assert.deepEqual(response.json, unauthorized);
    });
  }

  test('a replayed request ID is rejected', async () => {
    const request = fake.signRequest({ ...ping, method: 'POST', json: { version: 2 } });

    const first = await fake.send(request);
    const replay = await fake.send(request);

    assert.equal(first.status, 200, first.text);
    assert.equal(replay.status, 401, replay.text);
    assert.deepEqual(replay.json, unauthorized);
  });

  test('once the site is enrolled, keys of the other environment are rejected', async () => {
    await site.wpOk('option', 'update', 'gq_payments_environment', 'sandbox');
    try {
      const production = await fake.callSite({ ...ping, keyId: 'test-production-current' });
      const sandbox = await fake.callSite(ping);

      assert.equal(production.status, 401, production.text);
      assert.equal(sandbox.status, 200, sandbox.text);
    } finally {
      await site.wp('option', 'delete', 'gq_payments_environment');
    }
  });
});

describe('outbound calls to the Payment Service', () => {
  /** Asks the site (via a signed inbound call) to call the fake and report what it got back. */
  const relay = async (call) => {
    const response = await fake.callSite({ method: 'POST', route: '/gq-test/v1/relay', json: call });
    assert.equal(response.status, 200, response.text);
    return response.json;
  };

  test('a signed round trip: the site signs with its Site Key and gets the service reply', async () => {
    fake.respond('POST', '/v1/test/echo', (call) => ({ status: 201, json: { echoed: call.json } }));

    const result = await relay({ method: 'POST', path: '/v1/test/echo?mode=full', payload: { amount: '12.30', note: 'café/€' } });

    assert.deepEqual(result, { status: 201, json: { echoed: { amount: '12.30', note: 'café/€' } } });
    assert.equal(fake.calls.length, 1);
    const [call] = fake.calls;
    assert.equal(call.verified, true, 'signature verifies against the Site Key');
    assert.equal(call.path, '/v1/test/echo?mode=full');
    assert.equal(call.siteId, TEST_SITE_ID);
    assert.equal(call.keyId, 'site-key-0001');
    assert.equal(call.body, '{"amount":"12.30","note":"café/€"}', 'exact JSON bytes are signed');
  });

  test('a GET with an empty body is signed too', async () => {
    fake.respond('GET', '/v1/site/status', { status: 200, json: { connected: true } });

    const result = await relay({ method: 'GET', path: '/v1/site/status' });

    assert.deepEqual(result, { status: 200, json: { connected: true } });
    assert.equal(fake.calls[0].verified, true);
  });

  test('redirects are not followed', async () => {
    fake.respond('GET', '/v1/site/status', { status: 302, headers: { location: `${fake.url}/v1/elsewhere` } });

    const result = await relay({ method: 'GET', path: '/v1/site/status' });

    assert.equal(result.status, 302);
    assert.deepEqual(fake.calls.map((call) => call.path), ['/v1/site/status']);
  });

  test('an oversized response is refused', async () => {
    fake.respond('GET', '/v1/site/status', { status: 200, body: 'x'.repeat(1024 * 1024 + 1) });

    const result = await relay({ method: 'GET', path: '/v1/site/status' });

    assert.deepEqual(result, { status: 0, json: null });
  });

  test('the service rejects calls not signed by a key it trusts', async () => {
    fake.trustSiteKeys([vectors.stranger_key.public_key]);

    const result = await relay({ method: 'GET', path: '/v1/site/status' });

    assert.equal(result.status, 401);
    assert.equal(fake.calls[0].verified, false);
  });
});

async function toResult(response) {
  const text = await response.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}
  return { status: response.status, json, text };
}
