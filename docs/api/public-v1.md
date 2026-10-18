# MSKBA HTTP API v1 — public venues

The API is **platform-neutral**: native iOS/Android applications, browser clients on
external sites, and server-to-server integrations all use the same HTTPS/JSON contract.

## Routes

| Method | URL | Access |
| --- | --- | --- |
| GET | /api/v1/venues | Public, read-only |
| GET | /api/get_venues | Compatibility alias with identical contract |

The versioned URL is **canonical for new clients**. No app-embedded API secret,
cookie or user session is required for this public endpoint. Do not depend on
the requester being iOS or Android.

## Query

- `limit`: optional integer, 1–100, default 30.
- `cursor`: optional opaque string from a previous response's `meta.next_cursor`.
  Never decode, construct, edit, or persist a cursor as a user identifier.

The ordering is by the unique venue ID ascending. Clients consume all pages until
`meta.has_more` is `false`; the last page's `meta.next_cursor` and
`links.next` are `null`. This is keyset pagination, not offset pagination.
Changes to the underlying dataset between requests can change what remains
visible; pagination does not create a frozen snapshot.

## Response

HTTP 200 JSON (illustrative values):

```json
{
  "data": [
    {
      "id": 123,
      "name": "Баскетбольный зал",
      "alias": "basketbolnyy-zal",
      "type": "sports_hall",
      "type_label": "Спортзал",
      "status": "confirmed",
      "operational_status": "active",
      "short_description": "Игровой зал",
      "address": "Москва, улица Примерная, 1",
      "latitude": 55.75,
      "longitude": 37.62,
      "image_url": "https://dev.mskba.ru/storage/venues/example.jpg",
      "url": "https://dev.mskba.ru/venues/123-basketbolnyy-zal"
    }
  ],
  "meta": {
    "limit": 30,
    "has_more": false,
    "next_cursor": null
  },
  "links": {
    "next": null
  }
}
```

- A venue is public only when `status=confirmed`. Unconfirmed, blocked,
  and soft-deleted venues are excluded, even for signed-in users.
- An operationally `temporarily_closed` confirmed venue stays visible.
- `type`, `status`, and `operational_status` are stable machine-readable
  enum values; `type_label` is display text and should not be parsed.
- `address`, `latitude`, `longitude`, `image_url`, and
  `short_description` may be `null`. URLs are absolute.
- No internal ownership, moderation drafts, roles, contacts or permissions
  are returned.
- Invalid query parameters produce HTTP 422 JSON validation errors.
  Excessive requests produce HTTP 429.
- API paths use Laravel's JSON exception rendering.

## Examples

```sh
curl -H 'Accept: application/json' \
  'https://dev.mskba.ru/api/v1/venues?limit=30'
```

```js
let cursor = null;
do {
  const url = new URL('https://dev.mskba.ru/api/v1/venues');
  url.searchParams.set('limit', '30');
  if (cursor !== null) url.searchParams.set('cursor', cursor);
  const response = await fetch(url, { credentials: 'omit' });
  if (!response.ok) throw new Error('API HTTP ' + response.status);
  const page = await response.json();
  // Process page.data here.
  cursor = page.meta.next_cursor;
} while (cursor !== null);
```

## Browser compatibility (CORS)

For public `api/*` paths, the response permits any Origin (`*`) but does
not allow credentialed cross-origin cookies. The default `fetch` examples
explicitly omit credentials. HTTPS, input validation and per-route throttling
are still required; CORS is not authentication and does not limit non-browser
clients. For future cookie-based first-party SPA sessions, define a separate
restricted-origin, credentials-enabled policy. Never turn on wildcard origins
and credentialed cookies simultaneously.

## Next phase: user authentication

Public resources stay public. Private endpoints (user profile, own notifications,
preferences and mutations) require an authenticated user with authorization checks.
Consider Laravel Sanctum for first-party native Bearer tokens and same-site SPA
cookie sessions. Independent external sites asking to act *for users* should
use an explicit delegated authorization flow (OAuth/OIDC with PKCE) or another
purpose-built integration, never another site's hard-coded MSKBA user credentials.
Document token rotation/revocation, device handling, rate limits, and the data
permissions when user login is implemented.

App Attest / Play Integrity can later be added as defense-in-depth for native
clients without making the open public catalog depend on either platform.
