# MSKBA API v1 — User authentication

**Status:** first-party, stateless mobile/API login (iOS / Android); no separate
mobile user database. Available only on DEV until independently released.

## Quick start

A developer can create their own test account via
`https://dev.mskba.ru/register` and use the same username/password here.
Do not ask developers to share their credentials, passwords, or bearer tokens.
The website login continues to use the normal secure browser session.

### 1. Sign in

```http
POST /api/v1/auth/login
Accept: application/json
Content-Type: application/json

{"login":"my-username","password":"my-password","device_name":"ios-test-phone"}
```

- `login`: username or a **verified** existing contact (same rules as website).
- `password`: that identity's password.
- `device_name`: optional, non-secret 1–80-character client label (e.g.
  `ios-iphone` or `android-pixel`). Never send hardware serial numbers.
- Users without passwords (e.g. some Telegram-only accounts) must establish
  a password through existing account settings or a separately implemented
  native social-auth flow. Web login cookies do not count as an API token.
- Unconfirmed users may log in just as on the website. Blocked users may not.

HTTP 200 JSON:

```json
{
  "token_type": "Bearer",
  "access_token": "<opaque personal access token>",
  "expires_at": "2026-11-18T10:00:00+00:00",
  "requires_password_change": false,
  "user": {
    "id": 42,
    "username": "my-username",
    "status": "unconfirmed",
    "profile": {
      "first_name": "Имя",
      "last_name": "Фамилия",
      "middle_name": null,
      "gender": null,
      "birth_date": null,
      "avatar_url": null
    },
    "participation_roles": ["player"]
  }
}
```

Token expires after **30 days**. Obtain a new token by signing in again when
it expires (refresh tokens not included in this phase).

- HTTP 401: incorrect login or password.
- HTTP 403: blocked identity.
- HTTP 422: request validation.
- HTTP 429: login throttling (5 attempts/minute per route-scoped client key).

### 2. Load the current user's profile

```http
GET /api/v1/me
Authorization: Bearer <access_token>
Accept: application/json
```

Returns HTTP 200 with `{"data": <user object>}` or HTTP 401 with an
invalid/expired/revoked token. A blocked canonical account or blocked linked
identity returns HTTP 403.

### 3. Log out of this device

```http
POST /api/v1/auth/logout
Authorization: Bearer <access_token>
Accept: application/json
```

Returns HTTP 200, then the provided bearer token no longer works.
Other device tokens remain valid.

## Security rules

- Always use HTTPS. Only deliver credentials as JSON in the POST request body.
- Never include the token in URLs, analytics, crash reports, screenshots or logs.
- Store bearer tokens in iOS **Keychain** / Android **Keystore-backed**
  secure storage; do not put them in localStorage, AsyncStorage or plain files.
- API tokens are scoped to `profile:read` and `auth:logout`.
  API middleware checks both active account status and token abilities.
- Tokens are stored **hashed** by Laravel Sanctum; the raw token appears only
  in the login response, once.
- Read-only venue catalog endpoints stay public and never require a token.
- The authentication API is stateless: login does not set up an authenticated
  Laravel web session, and profile/logout require a real Sanctum personal token.
- Cross-origin CORS without cookies is suitable for public browser calls,
  **not** a recommendation to put long-lived bearer tokens in untrusted browser
  JavaScript. Delegated third-party user access requires a dedicated OAuth/OIDC
  design and scopes, not passwords of MSKBA users.
- Future work: native sign-up and provider login, password reset, token listing
  and mass revocation, session/device management, shorter token lifetimes,
  optional native App Attest / Play Integrity.

## cURL (do not paste credentials into shared terminal history)

Use a secured client or interactive prompt instead of shell-history literals.
To inspect only the HTTP status / schema during QA, prefer dedicated test
credentials that aren't shared with others.
