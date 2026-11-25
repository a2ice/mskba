# Mobile account confirmation API

All paths use /api/v1 and require a real Bearer token. Existing read-only tokens retain GET /account/confirmation; log in again for new scopes profile:write, contacts:read, contacts:write, account:confirm. No cookie fallback, no system role or user ID accepted.

| Method | Path (after /api/v1) | Body | Result |
| --- | --- | --- | --- |
| GET | /account/confirmation | — | data.status, data.can_confirm, data.requirements (five keys), data.primary_contact |
| PATCH | /me/profile | partial first_name, last_name, middle_name, birth_date (YYYY-MM-DD), gender | data (OwnUser) |
| GET | /me/contacts | — | data array |
| POST | /me/contacts | type=email/phone, value, optional label | 201 data (contact); no code sent |
| POST | /me/contacts/{contact}/verification | — | data.contact_id, expires_at, seconds_left |
| POST | /me/contacts/{contact}/verification/confirm | code (six-digit string) | data (contact) |
| PATCH | /me/contacts/{contact}/primary | — | data (contact) |
| POST | /account/confirmation | first_name, last_name, birth_date, gender | data (OwnUser, status confirmed) |

Example final confirmation: {"first_name":"Иван","last_name":"Иванов","birth_date":"1999-01-01","gender":"male"}. Explicit review payload follows the existing web contract. The server saves the four submitted fields and confirms atomically; a verified primary contact remains mandatory. GET status reports saved values only. The PATCH endpoint shares the existing profile permission and audit implementation. Confirmed personal data changes without the operational self-edit permission return 403.

Contact response includes id, type, value (display value), label, is_primary, is_verified, can_verify_by_code, verification_expires_at and seconds_left. First successfully verified contact becomes primary according to the existing domain rule. Email code uses the current strategy (60 minute TTL, five attempts, 60 second cooldown); rely on returned expires_at/seconds_left. Phone can be stored but SMS is unavailable. Telegram/VK contacts can be listed or made primary if already verified; native provider linking is not part of these endpoints. No raw verification hashes or codes in API responses.

Errors: 401 invalid/missing Bearer; 403 blocked or missing scope/permission; 404 foreign/missing contact; 422 invalid input or state; 429 rate limit/cooldown (seconds_left for cooldown); 503 delivery unavailable. Codes and contacts belong only to the authenticated canonical identity.

[OpenAPI](openapi-v1.json) · [Identity specification](../specification/identity-account-confirmation.md)
