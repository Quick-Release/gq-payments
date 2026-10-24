// Brings the site to "connected" the way an administrator and an Operator would: a Connection
// Request from GETQUICK → Payments, approval on the scripted Payment Service, then a status refresh.
import assert from 'node:assert/strict';
import { paymentService } from './payment-service.mjs';
import { singleSite } from './site.mjs';

export const MBWAY = { id: 'mbway', label: 'MB WAY', status: 'eligible', enabled: true };
export const MULTIBANCO = { id: 'multibanco', label: 'Multibanco', status: 'eligible', enabled: true };

const CONNECTION_OPTIONS = ['gq_payments_site_id', 'gq_payments_site_keys', 'gq_payments_connection', 'gq_payments_environment'];

/** Forgets the site's connection (options and status cache). */
export async function forgetConnection(target = singleSite) {
  for (const option of CONNECTION_OPTIONS) await target.wp('option', 'delete', option);
  await target.wp('transient', 'delete', 'gq_payments_connection_fresh');
}

/**
 * Connects the site to the fake with these Eligible Methods.
 * Resolves { service, siteId, refresh() }; refresh() makes the site re-read the service's status now.
 */
export async function connectSite(fake, { methods = [MBWAY], target = singleSite } = {}) {
  await forgetConnection(target);
  const service = paymentService(fake, { methods });
  const get = await target.loginAs('admin');

  const submit = async (action) => {
    const page = await get('/wp-admin/admin.php?page=gq-payments');
    const fields = findForm(page.html, action);
    assert.ok(fields, `no ${action} form on GETQUICK → Payments`);
    const result = await get('/wp-admin/admin-post.php', { form: fields });
    assert.equal(result.status, 302, result.html.slice(0, 500));
  };

  await submit('gq_payments_connection_request');
  service.approve();
  await submit('gq_payments_refresh_status');

  return {
    service,
    siteId: await target.wpOk('option', 'get', 'gq_payments_site_id'),
    refresh: () => submit('gq_payments_refresh_status'),
  };
}

function findForm(html, action) {
  for (const [, form] of html.matchAll(/<form\b[^>]*>([\s\S]*?)<\/form>/g)) {
    const fields = {};
    for (const [input] of form.matchAll(/<(?:input|button)\b[^>]*>/g)) {
      const name = /\bname="([^"]*)"/.exec(input)?.[1];
      const value = /\bvalue="([^"]*)"/.exec(input)?.[1] ?? '';
      if (name) fields[name] = value.replace(/&amp;/g, '&');
    }
    if (fields.action === action) return fields;
  }
  return null;
}
