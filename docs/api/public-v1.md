# MSKBA HTTP API v1 — public venues

The API is **platform-neutral**: native iOS/Android applications, browser clients on
external sites, and server-to-server integrations all use the same HTTPS/JSON contract.

## Routes

| Method | URL | Access |
| --- | --- | --- |
| GET | /api/v1/venues | Public, read-only |
| GET | /api/v1/venues/{venue} | Один опубликованный объект площадки по числовому ID |

Both endpoints are public and versioned. The former `/api/get_venues`
compatibility alias was retired on DEV when the venue detail method was added.
Clients must use `/api/v1/venues` for lists. No embedded application secret,
cookie or user session is required for these public endpoints.

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

## Single venue: GET /api/v1/venues/{venue}

To fetch one record, supply its numeric `id` (e.g. `1`) from the list response.
Use the same field structure as one item in `data`, wrapped in a `data` object:

```sh
curl -sS -H 'Accept: application/json' \
  'https://dev.mskba.ru/api/v1/venues/1' | jq .
```

```json
{
  "data": {
    "id": 1,
    "name": "Школа №1794",
    "alias": "shkola-1794",
    "type": "school",
    "type_label": "Школа",
    "status": "confirmed",
    "operational_status": "active",
    "short_description": null,
    "address": "Россия, Москва, Керамический проезд, 47А",
    "latitude": null,
    "longitude": null,
    "image_url": null,
    "url": "https://dev.mskba.ru/venues/1-shkola-1794"
  }
}
```

This is an illustrative response: actual fields follow the stored DEV fixture.
Missing, unconfirmed, blocked and soft-deleted venues return HTTP 404 with
`{"message":"Площадка не найдена."}` (the record's visibility is not disclosed).
Nonnumeric IDs fail route matching with 404. Per-route throttle is 60/minute.
No cursor, list metadata or pagination is present for a single venue.

## Browser compatibility (CORS)

For public `api/*` paths, the response permits any Origin (`*`) but does
not allow credentialed cross-origin cookies. The default `fetch` examples
explicitly omit credentials. HTTPS, input validation and per-route throttling
are still required; CORS is not authentication and does not limit non-browser
clients. For future cookie-based first-party SPA sessions, define a separate
restricted-origin, credentials-enabled policy. Never turn on wildcard origins
and credentialed cookies simultaneously.

## Authentication

The public venue endpoints remain available without a token. Authenticated
REST operations use Laravel Sanctum: `POST /api/v1/auth/login`,
`GET /api/v1/me`, `POST /api/v1/auth/logout`. See
[mobile-auth-v1.md](mobile-auth-v1.md). The versioned OpenAPI contract
[openapi-v1.json](openapi-v1.json) is exposed through the permission-gated
Swagger UI in Filament on DEV.
