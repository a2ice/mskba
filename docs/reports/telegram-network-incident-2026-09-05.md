# Telegram network incident — 2026-09-05

## Symptoms

- Bot `/start` updates were delayed or not delivered.
- Telegram bot login sometimes completed only after repeated attempts.
- Bot API publication/edit jobs repeatedly timed out.

## Production diagnostics

Application and Docker services were healthy, including the dedicated Redis workers `telegram-inbound-queue` and `telegram-background-queue`.

External probing confirmed that `https://mskba.ru/api/integrations/telegram/webhook` is publicly reachable and rejects requests without the configured Telegram secret as expected.

From both the VDS host and the PHP container, normal HTTPS destinations such as Google and GitHub were reachable, while Telegram networks timed out on TCP/443. Tested Telegram addresses included:

- `149.154.166.110`
- `149.154.167.220`
- `91.108.56.130`
- `91.108.4.1`

Generic destinations `1.1.1.1:443` and `8.8.8.8:443` were reachable from the same host/container.

A successful `getWebhookInfo` call during a short connectivity window reported:

- webhook URL: `https://mskba.ru/api/integrations/telegram/webhook`
- `pending_update_count`: 13
- `last_error_message`: `Connection timed out`

This proves that Telegram had pending updates which it could not deliver to the production webhook.

IPv6 does not currently provide a fallback path to Telegram from the application container.

## Conclusion

The remaining Telegram outage is outside Laravel/Redis/Docker application routing. The production network path selectively fails for Telegram address ranges in both directions. Host/provider firewall or upstream routing must be checked.

Until the network issue is resolved, web authentication should expose the official Telegram Login Widget as a fallback because its signed login payload is validated locally by MSKBA and does not require the VDS to call the Bot API during the login handshake.

## Provider request

Ask the hosting/network provider to verify bidirectional reachability for Telegram Bot API/webhook networks, especially TCP/443, and confirm that no ACL, anti-DDoS filter, route policy or provider firewall blocks Telegram prefixes. Telegram webhook source ranges documented by Telegram should be allowed to reach HTTPS on `mskba.ru`.
