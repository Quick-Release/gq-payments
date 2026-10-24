// gq-payments-v1 wire format in Node (node:crypto Ed25519). Mirrors src/Transport/Wire.php;
// both are pinned by tests/fixtures/signing.json.
import { createHash, createPrivateKey, createPublicKey, randomUUID, sign, verify } from 'node:crypto';

export const PREFIX = 'gq-payments-v1';
export const WINDOW_SECONDS = 300;
export const HEADERS = {
  keyId: 'GQ-Payments-Key-Id',
  siteId: 'GQ-Payments-Site-Id',
  timestamp: 'GQ-Payments-Timestamp',
  requestId: 'GQ-Payments-Request-Id',
  digest: 'Content-Digest',
  signature: 'GQ-Payments-Signature',
};

const b64u = (bytes) => Buffer.from(bytes).toString('base64url');

/** Strict standard base64: exact length and lossless round trip, else null. */
function decode(text, length) {
  if (typeof text !== 'string') return null;
  const bytes = Buffer.from(text, 'base64');
  return bytes.length === length && bytes.toString('base64') === text ? bytes : null;
}

/** RFC 9530 Content-Digest of the exact body. */
export const digest = (body) => `sha-256=:${createHash('sha256').update(body ?? '', 'utf8').digest('base64')}:`;

export const canonical = ({ method, path, keyId, siteId, timestamp, requestId, contentDigest }) =>
  [PREFIX, method.toUpperCase(), path, keyId, siteId, String(timestamp), requestId, contentDigest].join('\n');

/** RFC 3986 encoding matching PHP's rawurlencode(). */
const encode = (value) => encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);

/** Signed path of a REST request to the site: route plus sorted query without rest_route (Wire::inboundPath). */
export function inboundPath(route, query = {}) {
  const pairs = Object.entries(query)
    .filter(([name]) => name !== 'rest_route')
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
    .map(([name, value]) => `${encode(name)}=${encode(String(value))}`);
  return pairs.length ? `${route}?${pairs.join('&')}` : route;
}

/** Signed headers. secretKey: base64 64-byte sodium secret (seed ‖ public key). */
export function signHeaders({ method, path, body = '', keyId, siteId, secretKey, timestamp, requestId }) {
  const secret = decode(secretKey, 64);
  if (!secret) throw new Error('secretKey must be a base64 64-byte Ed25519 secret key');
  const privateKey = createPrivateKey({
    key: { kty: 'OKP', crv: 'Ed25519', d: b64u(secret.subarray(0, 32)), x: b64u(secret.subarray(32)) },
    format: 'jwk',
  });
  const ts = String(timestamp ?? Math.floor(Date.now() / 1000));
  const id = requestId ?? randomUUID();
  const contentDigest = digest(body);
  const message = canonical({ method, path, keyId, siteId, timestamp: ts, requestId: id, contentDigest });
  return {
    [HEADERS.keyId]: keyId,
    [HEADERS.siteId]: siteId,
    [HEADERS.timestamp]: ts,
    [HEADERS.requestId]: id,
    [HEADERS.digest]: contentDigest,
    [HEADERS.signature]: sign(null, Buffer.from(message), privateKey).toString('base64'),
  };
}

/** Header lookup that ignores case. */
export function header(headers, name) {
  const wanted = name.toLowerCase();
  for (const [key, value] of Object.entries(headers)) {
    if (key.toLowerCase() === wanted) return Array.isArray(value) ? value.join(', ') : String(value);
  }
  return '';
}

/** Verifies a signed request against one public key (signature, digest and window only; no replay check). */
export function verifyRequest({ method, path, body = '', headers, publicKey, now = Math.floor(Date.now() / 1000) }) {
  try {
    const timestamp = header(headers, HEADERS.timestamp);
    const requestId = header(headers, HEADERS.requestId);
    if (!/^\d{10}$/.test(timestamp) || Math.abs(now - Number(timestamp)) > WINDOW_SECONDS) return false;
    if (!/^[a-zA-Z0-9-]{16,128}$/.test(requestId)) return false;
    const contentDigest = digest(body);
    if (header(headers, HEADERS.digest) !== contentDigest) return false;
    const key = decode(publicKey, 32);
    const signature = decode(header(headers, HEADERS.signature), 64);
    if (!key || !signature) return false;
    const message = canonical({
      method, path, keyId: header(headers, HEADERS.keyId), siteId: header(headers, HEADERS.siteId),
      timestamp, requestId, contentDigest,
    });
    const verifier = createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: b64u(key) }, format: 'jwk' });
    return verify(null, Buffer.from(message), verifier, signature);
  } catch {
    return false;
  }
}

/**
 * A replay-aware verifier, as the fake Payment Service uses for the site's calls: a request is
 * genuine when one of `publicKeys` verifies it (signature, digest and window), and each
 * (key ID, request ID) pair is accepted once. Forgeries never consume a request ID.
 */
export function createVerifier() {
  const seen = new Set();
  return {
    verify({ method, path, body = '', headers, publicKeys, now }) {
      const genuine = publicKeys.some((publicKey) => verifyRequest({ method, path, body, headers, publicKey, now }));
      if (!genuine) return false;
      const claim = `${header(headers, HEADERS.keyId)}\n${header(headers, HEADERS.requestId)}`;
      if (seen.has(claim)) return false;
      seen.add(claim);
      return true;
    },
  };
}
