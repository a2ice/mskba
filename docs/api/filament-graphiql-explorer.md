# MSKBA GraphQL Explorer — Filament DEV

GraphiQL is embedded in **Filament 5**, not a publicly accessible `/graphiql` page.

- UI: `https://dev.mskba.ru/backoffice/graphql-explorer`
- Menu: **Разработка → GraphQL Explorer**
- Endpoint used by the IDE: `POST /api/graphql` (unchanged)
- Bundled dependencies: local Vite entry with GraphiQL 5, React 19,
  GraphQL and the GraphiQL toolkit; no public CDN or remote executable JS.

## Authorization

A dedicated operational permission controls the Filament UI:

`backoffice.graphql_explorer.view`

- **Superadmin:** granted automatically, non-revocable.
- **Other confirmed staff:** no default grant. A superadmin can grant the
  permission for an eligible staff role from **Пользователи и права → Filament**.
  A corresponding system-role profile can opt in through the existing
  **Профили системных ролей** editor.
- **Manager:** permitted as a target for delegated access when the role
  retains `backoffice.access`; giving GraphiQL does **not** give additional
  admin privileges to the user.
- In addition to the new permission, the user must retain `backoffice.access`.
- Unconfirmed, blocked, ordinary USER and SYSTEM accounts cannot use Filament.
- Navigation and direct page visits both use `GraphQLExplorer::canAccess()`.
  Filament's Livewire page middleware checks permissions again on hydration.
- Available only for `APP_ENV=local|staging|testing`; production disabled.

## Using the editor

Use the built-in query editor, variables, response pane, auto-completion
and schema Documentation explorer. Examples:

```graphql
query {
  venues(first: 2) {
    edges { node { id name type } }
    pageInfo { hasNextPage endCursor }
  }
}
```

Private profile requires a Sanctum token with `profile:read`. Create the
token through the existing **DEV** REST login endpoint
(`POST /api/v1/auth/login`) using your own test account; paste it into
the separate **Bearer-токен DEV** field. The field masks the value and does
not store it in the DB or localStorage. Clear it after testing.

The Filament web session is **not** a valid GraphQL `me` API credential;
the resolver still checks a real Sanctum Bearer token.

Changing the query in the editor does not modify the server-side GraphQL
schema. Expand `graphql/schema.graphql` and resolvers via PR + CI/deploy.
No GraphQL mutation field is available at present.

## Security

Do not paste production tokens or share test tokens. Remember that GraphiQL
may store queries in the browser; keep secrets out of query text and variables.
For mobile integration use scoped, expiring bearer tokens stored in secure
native OS storage. GraphQL request limits and data visibility remain
enforced server-side independent of IDE permissions.
