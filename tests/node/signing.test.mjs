// Seam C (Node side): the fake Payment Service's signing agrees with the plugin on the wire format.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import { inboundPath, signHeaders, verifyRequest } from '../e2e/fake-service/signing.mjs';

const vectors = JSON.parse(readFileSync(new URL('../fixtures/signing.json', import.meta.url), 'utf8'));
const secretFor = (signer) => (signer === 'site' ? vectors.site_key : vectors.service_keys[signer]).secret_key;
const publicFor = (signer) => (signer === 'site' ? vectors.site_key : vectors.service_keys[signer]).public_key;
const now = (vector) => Number(vector.headers['GQ-Payments-Timestamp']);
const wire = (vector) => ({
  ...vector.headers,
  'Content-Digest': vector.content_digest,
  'GQ-Payments-Signature': vector.signature,
});

describe('signing vectors', () => {
  for (const vector of vectors.cases) {
    test(`signs: ${vector.name}`, () => {
      const headers = signHeaders({
        method: vector.method,
        path: vector.path,
        body: vector.body,
        keyId: vector.headers['GQ-Payments-Key-Id'],
        siteId: vector.headers['GQ-Payments-Site-Id'],
        secretKey: secretFor(vector.signer),
        timestamp: now(vector),
        requestId: vector.headers['GQ-Payments-Request-Id'],
      });
      assert.deepEqual(headers, wire(vector));
    });

    test(`verifies: ${vector.name}`, () => {
      const request = { method: vector.method, path: vector.path, body: vector.body, headers: wire(vector) };
      const publicKey = publicFor(vector.signer);

      assert.equal(verifyRequest({ ...request, publicKey, now: now(vector) }), true);
      assert.equal(verifyRequest({ ...request, publicKey, now: now(vector) + 300 }), true);
      assert.equal(verifyRequest({ ...request, publicKey, now: now(vector) + 301 }), false, 'stale');
      assert.equal(verifyRequest({ ...request, publicKey: vectors.stranger_key.public_key, now: now(vector) }), false, 'wrong key');
      assert.equal(verifyRequest({ ...request, body: `${vector.body} `, publicKey, now: now(vector) }), false, 'bad digest');
      assert.equal(verifyRequest({ ...request, path: `${vector.path}x`, publicKey, now: now(vector) }), false, 'path');
    });
  }

  test('lowercase header names verify too', () => {
    const vector = vectors.cases[0];
    const headers = Object.fromEntries(Object.entries(wire(vector)).map(([k, v]) => [k.toLowerCase(), v]));
    assert.equal(verifyRequest({ method: vector.method, path: vector.path, body: vector.body, headers, publicKey: vectors.site_key.public_key, now: now(vector) }), true);
  });

  for (const vector of vectors.inbound_paths) {
    test(`inbound path: ${vector.name}`, () => {
      assert.equal(inboundPath(vector.route, vector.query), vector.path);
    });
  }
});
