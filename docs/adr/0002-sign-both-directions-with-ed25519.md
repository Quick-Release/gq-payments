# Sign both directions with Ed25519 and pin the Service Key in the release

The site signs its requests to the Payment Service with its own Ed25519 Site Key. The Payment Service signs its messages to the site with a Service Key. The plugin release ships that key's public half, as a current and a next key for each environment, and only the `GQ_PAYMENTS_SERVICE_PUBLIC_KEYS` constant can override it. With asymmetric keys in both directions, WordPress holds no secret that could forge a "paid" message. Neither an administrator nor a database write can choose which messages count as genuine. A leak on the service side cannot impersonate a site. The header scheme follows gq-support: key ID, timestamp, single-use request ID and `Content-Digest`.

## Considered Options

- A shared-secret header or HMAC. Rejected because the same secret could both verify messages and forge them.
- Fetching the Service Key when the site connects. Rejected because it makes trust depend on a writable option.

## Consequences

- Rotating the Service Key requires a plugin release that ships the next key ahead of time.
- The service URL is a constant that only `GQ_PAYMENTS_SERVICE_URL` in wp-config can override, never an admin option. The Site Enrollment decides the environment (sandbox or production), not WordPress.
- The Site Key is read from `GQ_PAYMENTS_SITE_KEY` when set. Otherwise it is generated into an option with autoload off, and the admin screen warns that this is weaker.
