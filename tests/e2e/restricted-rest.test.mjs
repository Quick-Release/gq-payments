// Seam A fixture check: the gq-config stub's restricted-REST gate (tests/e2e/mu-plugins/gq-config-stub.php)
// behaves like gq-config's Access_Policy::rest_request, so later slices can test their allow-list entries.
// Steer it with options gq_test_anonymous_rest_mode ('core' | 'restricted') and
// gq_test_public_rest_routes (JSON { "/route" or "/prefix/*": ["GET", ...] }).
import assert from 'node:assert/strict';
import { after, afterEach, before, describe, test } from 'node:test';
import { startFakeService, TEST_SITE_ID } from './fake-service/fake-service.mjs';
import { baseline, singleSite as site } from './lib/site.mjs';

let fake;

before(async () => {
  fake = await startFakeService();
  await baseline();
  await site.wpOk('option', 'update', 'gq_payments_site_id', TEST_SITE_ID);
});
afterEach(async () => {
  await site.wp('option', 'delete', 'gq_test_anonymous_rest_mode');
  await site.wp('option', 'delete', 'gq_test_public_rest_routes');
});
after(async () => {
  await site.wp('option', 'delete', 'gq_payments_site_id');
  await fake?.close();
});

const anonymous = async (path) => {
  const response = await fetch(`${site.url}/wp-json${path}`);
  return { status: response.status, json: await response.json() };
};
const restricted = () => site.wpOk('option', 'update', 'gq_test_anonymous_rest_mode', 'restricted');
const allow = (routes) => site.wpOk('option', 'update', 'gq_test_public_rest_routes', JSON.stringify(routes), '--format=json');

describe('gq-config restricted REST gate (stub)', () => {
  test('in core mode anonymous REST is open, except users', async () => {
    assert.equal((await anonymous('/wp/v2/posts')).status, 200);

    const users = await anonymous('/wp/v2/users');
    assert.equal(users.status, 401);
    assert.equal(users.json.code, 'getquick_account_auth_required');
  });

  test('in restricted mode anonymous requests to unlisted routes need authentication', async () => {
    await restricted();

    const posts = await anonymous('/wp/v2/posts');

    assert.equal(posts.status, 401);
    assert.equal(posts.json.code, 'getquick_rest_auth_required');
  });

  test("gq-config's own storefront route is listed by default", async () => {
    await restricted();

    // The stub has no such route: passing the gate means WordPress answers 404, not 401.
    assert.equal((await anonymous('/getquick-config/v1/storefront')).status, 404);
  });

  test('a /prefix/* entry allows the listed methods only', async () => {
    await restricted();
    await allow({ '/gq-test/v1/*': ['GET'] });

    const get = await fake.callSite({ method: 'GET', route: '/gq-test/v1/ping' });
    const post = await fake.callSite({ method: 'POST', route: '/gq-test/v1/ping', json: {} });

    assert.equal(get.status, 200, get.text);
    assert.equal(post.status, 401);
    assert.equal(post.json.code, 'getquick_rest_auth_required');
  });

  test('an exact entry does not match sub-routes', async () => {
    await restricted();
    await allow({ '/gq-test/v1': ['GET', 'POST'] });

    const response = await fake.callSite({ method: 'GET', route: '/gq-test/v1/ping' });

    assert.equal(response.status, 401);
    assert.equal(response.json.code, 'getquick_rest_auth_required');
  });

  test('logged-in users bypass the gate', async () => {
    await restricted();
    const get = await site.loginAs();
    const nonce = (await get('/wp-admin/admin-ajax.php?action=rest-nonce')).html;

    const posts = await get('/wp-json/wp/v2/posts', { headers: { 'x-wp-nonce': nonce } });

    assert.equal(posts.status, 200);
  });
});
