# Avatar generation: defer insufficient-funds warning and show button loader

Date: 2026-11-07. Target: `/account/participation/player?tab=physical` on dev only.

## Bug
When the wallet contains exactly one generation's price (100 ₽ in the reported case), a successful `generate_2d` request charges/reserves those funds while the GitHub/OpenAI job is still `pending`. The client immediately requested a second `generation_quote` and displayed **Недостаточно средств** and hid the generation controls during the running job. A failed job may refund those funds, so the warning implied an irreversible failed payment even before the result.

## UX and billing contract
- Check price and available funds before starting a charge, preserving the existing server-side validation and debit.
- As soon as the user presses **Сгенерировать**, disable the button and display a compact circular spinner inside it, using the same ring/rotation concept as the image-upload loading indicator; keep the readable button label and set `aria-busy=true`. Respect reduced-motion settings.
- Do **not** refresh the quote after receiving the `pending` response, because the displayed available balance is now temporarily reserved. Keep the pending/processing status message visible and existing fields in place.
- On confirmed `completed` or `failed`, refresh the balance/count and derive availability and the insufficient-funds warning from the refreshed server quote. The failed GitHub callback uses the existing idempotent wallet refund; no separate client-side refund is introduced.
- If polling times out or loses connection and the job is **not known to be terminal**, do not show a premature insufficient-funds warning; leave the button unavailable to prevent a duplicate charge. A page refresh or later history/status check can confirm the result.
- Hide the spinner and clear `aria-busy` for every exit path, including pre-flight validation failure and exceptions. Preserve the existing success scroll to the generated avatar and failure scroll to the error message.
- No new billing logic, API endpoints, payments, retries or production changes.

## UI Kit
Uses the existing `app-player-workspace__secondary` button and the established `image-upload-loading__spinner` 2–3 px rotating bordered-ring pattern, reduced to 16 px to fit inline, with the active accent and a reduced-motion duration. No custom animated GIF or external asset is needed.

## Acceptance
1. With exactly 100 ₽ and a 100 ₽ generation price, click **Сгенерировать** → spinner and disabled button, no **Недостаточно средств** while `pending` or `processing`.
2. On success → spinner disappears, image/selection and success feedback remain; balance/count refresh and (if still 0 ₽) the warning may appear **after** completion.
3. On failed generation → spinner disappears and error remains visible, wallet refund and new quote restore availability when funds are returned.
4. On a pending timeout / broken polling connection → no premature warning or duplicate paid request.
5. Existing initial insufficient-funds state before clicking still shows the warning and prevents charging.
6. CI PHP contract tests and frontend build pass; deploy dev via GitHub Actions, verify installed commit and /up. No paid API generation required for CI.
