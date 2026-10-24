// A scripted Payment Service for connection tests, built on the fake's respond()/trustSiteKeys().
// It models only what the site can observe: Site Enrollments (approved or revoked by an Operator,
// here the test), the environment, and the Eligible Methods with their on/off toggles.
//
// Wire contract (site → service, all signed with the Site Key):
// - POST /v1/site/enrollments   { site_id, site_url, public_key, fingerprint }, signed by the new key
//                               with an empty key ID → 201 { key_id, status: 'awaiting_approval' }
// - GET  /v1/site/status        → 200 { status: 'connected'|'awaiting_approval'|'disconnected'|'revoked',
//                                       environment: 'sandbox'|'production'|null, methods: [Method] }
// - POST /v1/site/configuration { methods: { <id>: bool } } → 200 { methods: [Method] }
// Method: { id, label, status: 'eligible'|'unavailable', enabled: bool }

export const DOWN = { status: 503, json: { code: 'internal', message: 'pg: password authentication failed for user "svc" at 10.1.2.3' } };

export function paymentService(fake, { environment = 'sandbox', methods = [] } = {}) {
  const enrollments = [];
  const state = { environment, methods: methods.map((m) => ({ ...m })), down: false };

  const trust = () => fake.trustSiteKeys(enrollments.map((e) => e.publicKey));
  const enrollmentOf = (call) => enrollments.find((e) => e.keyId === call.keyId);

  fake.acceptSelfSigned('POST', '/v1/site/enrollments');
  fake.respond('POST', '/v1/site/enrollments', (call) => {
    if (state.down) return DOWN;
    const enrollment = {
      keyId: `site-key-${String(enrollments.length + 1).padStart(4, '0')}`,
      siteId: call.siteId,
      siteUrl: call.json.site_url,
      publicKey: call.json.public_key,
      fingerprint: call.json.fingerprint,
      state: 'awaiting_approval',
    };
    enrollments.push(enrollment);
    trust();
    return { status: 201, json: { key_id: enrollment.keyId, status: enrollment.state } };
  });
  fake.respond('GET', '/v1/site/status', (call) => {
    if (state.down) return DOWN;
    const enrollment = enrollmentOf(call);
    if (!enrollment) return { status: 401, json: { code: 'unknown_key' } };
    const connected = enrollment.state === 'connected';
    return {
      status: 200,
      json: { status: enrollment.state, environment: connected ? state.environment : null, methods: connected ? state.methods : [] },
    };
  });
  fake.respond('POST', '/v1/site/configuration', (call) => {
    if (state.down) return DOWN;
    if (enrollmentOf(call)?.state !== 'connected') return { status: 403, json: { code: 'not_connected' } };
    for (const [id, enabled] of Object.entries(call.json?.methods ?? {})) {
      const method = state.methods.find((m) => m.id === id);
      if (!method || method.status !== 'eligible') return { status: 422, json: { code: 'method_unavailable' } };
      method.enabled = enabled === true;
    }
    return { status: 200, json: { methods: state.methods } };
  });

  const latest = () => enrollments.at(-1);

  return {
    enrollments,
    latest,
    /** Operator approves an enrollment (default: the latest); an approved enrollment replaces the site's earlier ones. */
    approve(enrollment = latest()) {
      for (const other of enrollments) {
        if (other !== enrollment && other.siteId === enrollment.siteId && other.state === 'connected') other.state = 'disconnected';
      }
      enrollment.state = 'connected';
    },
    /** Operator disconnects (or revokes) the site's current enrollment. */
    disconnect(how = 'disconnected') {
      for (const e of enrollments) if (e.state === 'connected') e.state = how;
    },
    setEnvironment(value) {
      state.environment = value;
    },
    setMethods(value) {
      state.methods = value.map((m) => ({ ...m }));
    },
    get methods() {
      return state.methods;
    },
    /** While down, every call answers 503 with internal details the site must not show. */
    setDown(value) {
      state.down = value;
    },
  };
}
