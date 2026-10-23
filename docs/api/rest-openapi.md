# MSKBA REST API — OpenAPI 3.1 + Swagger UI

The **existing REST v1** remains the primary contract for iOS, Android and
other platform clients. GraphQL/Lighthouse/GraphiQL remain installed but
are not expanded or changed by this feature.

## Using the explorer on DEV

1. Sign in to `https://dev.mskba.ru/backoffice` with a confirmed staff account.
2. Open **Разработка → REST API · Swagger** or
   `/backoffice/rest-api-explorer`.
3. Expand an endpoint, click **Try it out**, enter parameters, then
   **Execute** to run a real request against DEV.
4. For `GET /api/v1/me` and `POST /api/v1/auth/logout`, click
   **Authorize** and paste only your DEV Sanctum Bearer token.
   Swagger UI holds this token in memory; it is not persisted. Do not use
   real passwords or production tokens in DEV Swagger UI.
5. Download **OpenAPI 3.1 JSON** from the link above the explorer.
   The specification is usable by OpenAPI client generators for Swift/Kotlin.

**Repository source of truth:** `docs/api/openapi-v1.json`.
The served JSON and the Swagger UI use exactly this tracked file. The
spec has a relative server URL (`/`), so **Try it out** targets the host
where Swagger UI was opened. No proxy to production is configured.

The spec includes the currently implemented REST v1 routes, including
`GET /api/v1/venues/{venue}` for one confirmed venue by numeric ID.
The deprecated `/api/get_venues` alias was removed from DEV. It does **not**
expose nonexistent `/users`, `/teams`, or write endpoints. Adding new
features requires new Laravel operations, OpenAPI descriptions and tests.

## Authorization for documentation

Separate permission:
`backoffice.rest_api_explorer.view`.

- Default: **superadmin only**, subject to confirmed, unblocked account.
- Can be granted to permitted staff roles (e.g. manager) through the
  existing **Пользователи и права → Filament** editor.
- Requires `backoffice.access` as well. Page and JSON export both enforce
  the same permission; direct URL is not a bypass.
- Disabled outside local/staging/testing environments (including production).
- The Explorer's permissions do **not** change `/api/v1` authorization:
  public resources remain public; private ones require scoped Sanctum tokens.

## Compatibility and delivery

- GraphQL remains installed and unaffected.
- Locally bundled `swagger-ui-dist` 5.x via Vite; no CDN script or
  external Swagger Validator network call.
- CI checks JSON shape, API route coverage, authorization for the
  page/export route, and the normal Vite production build.
- Only deploy to `dev`. `main`/production are not modified.

## Contract evolution

REST resources should reuse current Application Use Cases, FormRequests and
API Resources rather than copy/paste business logic. Add `include` or `fields`
only for whitelisted links/fields with bounded pagination and permission checks.
Every added route must be described in OpenAPI and have API Feature tests.
