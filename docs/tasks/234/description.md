# 234 — Явная ошибка неподдерживаемого региона OpenAI

Production smoke Task 233 подтвердил, что OpenAI отвечает:

- HTTP 403
- code: `unsupported_country_region_territory`
- message: `Country, region, or territory not supported`

Исправления:
- этот provider code маппится в отдельный `ai_unsupported_region`;
- пользователь видит понятное сообщение о регионе сервера, а не ошибку прав API key;
- автоматический OpenAI smoke убран из каждого deploy, команда `ai:openai:smoke` остаётся доступной для ручной диагностики.

Фактическая причина не связана с key permissions, model allowlist или балансом OpenAI.
