// Fake Payment Service for integration tests. No provider, allocation or payment logic:
// it verifies and records the site's signed calls, answers them from a script, and sends
// signed requests to the site with the test Service Keys from tests/fixtures/signing.json.
//
// It runs inside the test process (start it in before(), close it in after()). WordPress
// in wp-env reaches it at https://host.docker.internal:<port>; tests/e2e/mu-plugins/gq-test-env.php
// points GQ_PAYMENTS_SERVICE_URL there and trusts its test-only certificate for that host only.
import { readFileSync } from 'node:fs';
import { createServer } from 'node:https';
import { HEADERS, header, inboundPath, signHeaders, verifyRequest } from './signing.mjs';

export const vectors = JSON.parse(readFileSync(new URL('../../fixtures/signing.json', import.meta.url), 'utf8'));
export const DEFAULT_PORT = 9443;
export const DEFAULT_KEY_ID = 'test-sandbox-current';
/** Site ID the test environment gives the site (gq-test-env.php sets the option). */
export const TEST_SITE_ID = vectors.cases[0].headers['GQ-Payments-Site-Id'];

const tls = (name) => readFileSync(new URL(`./tls/${name}`, import.meta.url));

/**
 * Starts the fake.
 *
 * @param {object} [options]
 * @param {number} [options.port]    Port on all interfaces (default 9443; the test mu-plugin allows it).
 * @param {string} [options.siteUrl] WordPress base URL for callSite() (default WP_BASE_URL or http://localhost:8988).
 */
export async function startFakeService({ port = DEFAULT_PORT, siteUrl = process.env.WP_BASE_URL ?? 'http://localhost:8988' } = {}) {
  /** @type {Array<object>} Outbound calls from the site, oldest first. */
  const calls = [];
  const scripts = new Map();
  const seen = new Set();
  let siteKeys = [vectors.site_key.public_key];

  const server = createServer({ key: tls('test-only-key.pem'), cert: tls('test-only-cert.pem') }, async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = Buffer.concat(chunks).toString('utf8');
    const path = req.url;
    const requestKey = `${header(req.headers, HEADERS.keyId)}\n${header(req.headers, HEADERS.requestId)}`;
    const signed = siteKeys.some((publicKey) => verifyRequest({ method: req.method, path, body, headers: req.headers, publicKey }));
    const replayed = signed && seen.has(requestKey);
    if (signed) seen.add(requestKey);

    let json = null;
    try {
      json = body ? JSON.parse(body) : null;
    } catch {}
    const call = {
      method: req.method,
      path,
      headers: req.headers,
      body,
      json,
      verified: signed && !replayed,
      keyId: header(req.headers, HEADERS.keyId),
      siteId: header(req.headers, HEADERS.siteId),
    };
    calls.push(call);

    let reply;
    if (!call.verified) {
      reply = { status: 401, json: { code: 'unauthorized' } };
    } else {
      const script = scripts.get(`${req.method} ${path.split('?')[0]}`);
      reply = typeof script === 'function' ? await script(call) : script ?? { status: 404, json: { code: 'not_found' } };
    }
    const payload = reply.json !== undefined ? JSON.stringify(reply.json) : reply.body ?? '';
    res.writeHead(reply.status ?? 200, {
      ...(reply.json !== undefined ? { 'content-type': 'application/json' } : {}),
      ...reply.headers,
    });
    res.end(payload);
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '0.0.0.0', resolve);
  });

  /** Builds a signed request to the site's REST API without sending it. */
  function signRequest({
    method = 'GET',
    route,
    query = {},
    json,
    body = json === undefined ? '' : JSON.stringify(json),
    keyId = DEFAULT_KEY_ID,
    siteId = TEST_SITE_ID,
    secretKey = vectors.service_keys[keyId]?.secret_key,
    timestamp,
    requestId,
    form = 'wp-json',
    headers = {},
  }) {
    const path = inboundPath(route, query);
    const signedHeaders = signHeaders({ method, path, body, keyId, siteId, secretKey, timestamp, requestId });
    const search = path.includes('?') ? path.slice(path.indexOf('?') + 1) : '';
    const url =
      form === 'rest_route'
        ? `${siteUrl}/?rest_route=${encodeURIComponent(route)}${search ? `&${search}` : ''}`
        : `${siteUrl}/wp-json${path}`;
    return {
      url,
      method,
      body,
      headers: { ...(body ? { 'content-type': 'application/json' } : {}), ...signedHeaders, ...headers },
    };
  }

  /** Sends a prepared request (again, for replay tests). Resolves { status, json, text, request }. */
  async function send(request) {
    const response = await fetch(request.url, {
      method: request.method,
      headers: request.headers,
      body: request.method === 'GET' || request.method === 'HEAD' ? undefined : request.body,
      redirect: 'manual',
    });
    const text = await response.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {}
    return { status: response.status, json, text, request };
  }

  return {
    /** Base URL as WordPress sees it. */
    url: `https://host.docker.internal:${port}`,
    port,
    /** Outbound calls recorded so far (live array). */
    calls,
    /**
     * Scripts the reply to the site's verified calls to METHOD PATH (path without query).
     * reply: { status, json | body, headers } or (call) => reply.
     */
    respond(method, path, reply) {
      scripts.set(`${method.toUpperCase()} ${path}`, reply);
    },
    /** Public keys (base64) accepted on the site's outbound calls; replaces the default fixture Site Key. */
    trustSiteKeys(publicKeys) {
      siteKeys = [...publicKeys];
    },
    /** Clears recorded calls and scripts, and trusts the fixture Site Key again. */
    reset() {
      calls.length = 0;
      scripts.clear();
      siteKeys = [vectors.site_key.public_key];
    },
    signRequest,
    send,
    /** Signs (with a test Service Key) and sends a request to the site's REST API. */
    callSite(options) {
      return send(signRequest(options));
    },
    close() {
      return new Promise((resolve) => {
        server.closeAllConnections();
        server.close(() => resolve());
      });
    },
  };
}
