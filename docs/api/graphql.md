# MSKBA GraphQL — DEV integration guide

## Purpose

Platform-neutral, **read-only** GraphQL alongside the existing REST API.
It allows iOS, Android, and browser applications to choose exact response
fields, using one typed schema. GraphQL is an **additional API**, not a replacement
for the existing mobile login, logout or venues endpoints.

- **DEV endpoint:** `POST https://dev.mskba.ru/api/graphql`
- Schema: `graphql/schema.graphql`
- Engine: Lighthouse v6, Laravel 13, PostgreSQL, existing Eloquent/Application services.
- A developer may inspect the schema using GraphQL introspection on DEV.
  Introspection is disabled by default in production environments.
- Schema cache is currently disabled to ensure deploys cannot serve an outdated
  compiled schema; introduce explicit schema cache rebuild when needed.
- No write mutations or subscriptions are exposed in this initial version.

## Public query: venues with cursor pagination

```graphql
query Venues($first: Int = 2, $after: String) {
  venues(first: $first, after: $after) {
    edges {
      cursor
      node {
        id
        name
        type
        address
        imageUrl
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
```

Pass `endCursor` as `after` to fetch the next page. First defaults to 30,
maximum 100. The underlying ordering/filter and cursors are the same as the
REST public venues API. Do not interpret cursors as database identifiers.

```sh
curl -sS 'https://dev.mskba.ru/api/graphql' \
  -H 'Content-Type: application/json' \
  --data '{"query":"{ venues(first: 2) { edges { node { id name type } } pageInfo { hasNextPage endCursor } } }"}' | jq .
```

A single venue:

```graphql
query {
  venue(id: "1") {
    id
    name
    alias
    type
    address
    imageUrl
  }
}
```

Only confirmed, non-deleted venues are available, including when a user
is logged in. Internal or unpublished fields are never exposed.

## Private query: current user

The existing REST login endpoint is unchanged:

```http
POST /api/v1/auth/login
Content-Type: application/json

{"login":"your_username","password":"your_password","device_name":"ios-dev"}
```

The login returns a token. Store it in iOS Keychain / Android secure storage,
then query:

```graphql
query {
  me {
    id
    username
    status
    profile { firstName lastName avatarUrl }
    participationRoles
  }
}
```

With:

```http
POST /api/graphql
Authorization: Bearer <token>
Content-Type: application/json
```

The same account rules, token permissions, revoked/expired tokens and blocked
aliases apply as in `GET /api/v1/me`. GraphQL execution errors generally
appear in the `errors` response array; do not rely on 401 for a failed
protected field inside an otherwise valid GraphQL document. Read `errors`
in addition to HTTP status.

Logout still uses `POST /api/v1/auth/logout` and revokes the current token.

## Operational and security expectations

- HTTPS, CORS for `api/*` with no cross-origin credentialed cookies.
- A route-level request throttle (60/min); complex queries are limited by
  depth and complexity, plus at most 100 venues per page.
- Clients cannot arbitrarily filter database tables or request undeclared
  fields; the server controls resolvers, privacy and query permissions.
- Prefer reusable fragments and typed query generation for mobile development.
- Do not embed user passwords, access tokens or static app secrets in source.
- Expand the schema incrementally based on actual mobile screens, adding
  separately tested query types for events, tournaments, teams, statistics etc.
- REST and GraphQL remain supported side by side; new mobile screens can use
  whichever endpoint fits without modifying existing clients.
