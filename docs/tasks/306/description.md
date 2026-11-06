# 306 — Реестр адаптеров и методов, API и browser contract

## Цель
Создать переиспользуемые provider adapters: API, Browser Agent (TinyFish/Playwright/OpenAI runner), Read-only, ручной источник. Один адаптер для N внешних площадок, но индивидуальные параметры каждого источника.

## Объём
- Contracts: getAvailability, getPricing, createBooking, getBookingStatus, cancelBooking — пока ПОДДЕРЖИВАЕМ схему future methods, фактически не выполняем createBooking/cancelBooking.
- Для API: несколько методов, HTTP GET/POST, URL/path/query/headers, token reference, version, response mapping. Защищённый HTTP client, allow-list, timeout, quotas, retries, credential isolation.
- Для Browser Agent: scenario identifier/version, executor, URL, max duration/steps, parameters, JSON schema validation. Не хранить ключи в сценарии.
- Схема command/result, correlation_id, idempotency_key, source_id, court mapping, timestamps, errors; untrusted page instructions не исполнять как системные команды.
- Пресеты адаптеров без копирования кода на каждую площадку. Идемпотентные callback, подписанные ответы, ручной тест и просмотр обезличенного результата.

## Acceptance
Mock API GET/POST/multimethod, mock browser runner, invalid/missing JSON, retry, forbidden external host, credentials masked, rights, versioning, no booking side effects. CI/deploy/ручная приёмка.
