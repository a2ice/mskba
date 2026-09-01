# Runbook rollout аренды площадок

## Ответственные и ворота

- Product owner принимает go/no-go; on-call backend отвечает за метрики, rollback и repair; владелец БД подтверждает DDL/backfill.
- Волны: `internal` → `allowlist` выбранных venue/contract → `percentage` 5/25/50% → `all`.
- Каждая волна длится не менее 24 часов и расширяется только после review метрик и выборочной сверки audit/outbox.
- SLO: p95 создания заявки < 1 с; p95 решения < 1 с; 99.9% due hold закрыты за 120 с; outbox p99 lag < 60 с; webhook/reconcile p99 lag < 300 с.
- Стоп-условия: ошибки команд > 2% за 15 минут, conflict rate вырос более чем вдвое к baseline, любой подтверждённый double booking, expiry lag > 300 с, outbox lag > 300 с, пять подряд ошибок webhook/reconcile.

## Deploy и миграции

1. Снять backup и анонимизированную production-like копию. Записать размер таблиц и `EXPLAIN` запросов availability/inbox/expiry.
2. Запустить expand migrations при выключенных master flags. Для DDL замерить lock wait; при превышении 5 с прервать и перенести индекс в online/concurrent процедуру конкретной СУБД.
3. Backfill всегда оформлять отдельной командой с `--dry-run`, batch ≤ 500, устойчивым `id > cursor`, счётчиками scanned/changed/skipped/failed и сохранением cursor. Текущая задача не требует backfill.
4. Выполнить `php artisan migrate:status`, `php artisan venue-booking:diagnose --json`, migration rollback rehearsal на копии и полный test suite.
5. Deploy кода, для первой закрытой волны включить `FEATURE_VENUE_RENTAL_FLOW=true` и
   `FEATURE_VENUE_RENTAL_PORTAL=true`, оставить `VENUE_RENTAL_ROLLOUT_MODE=internal`, очистить config
   cache и проверить страницы управления супер-администратором. Остальные `FEATURE_VENUE_RENTAL_*`
   включать только при готовности соответствующего сценария; затем расширять волны.
6. Contract migrations и удаление legacy consumers выполняются отдельной задачей после периода стабильности.

## Наблюдаемость и alerts

- Dashboard: command outcome/latency, conflicts, deadlock retries, overdue holds/lag, outbox backlog/lag, notification failures, stale payment intents, webhook failures и reconciliation lag.
- Команда `venue-booking:diagnose --json` не содержит PII и пригодна для health collector. Correlation ID берётся из command receipt/outbox, а provider reference показывается только маскированно.
- Warning: overdue holds > 0 две минуты, outbox lag > 60 с, stale payment intents > 0 пять минут. Critical: любой lag > 300 с, deadlock retries > 10/мин, failed webhook > 5/15 мин.
- Логи содержат только внутренние booking/venue IDs, correlation ID, outcome и error code. Нельзя логировать request payload webhook, evidence, инструкции, контакты, provider secret или подпись.

## Rollback и repair

1. Для мягкого rollback установить `VENUE_RENTAL_ROLLOUT_MODE=read_only`: чтение и обслуживание существующих данных остаются, новые mutation endpoints закрываются.
2. Для полного kill switch выключить соответствующий `FEATURE_VENUE_RENTAL_*` и очистить config cache. Не откатывать миграцию, пока новый код или данные её используют.
3. Проверить `venue-booking:diagnose`, затем вручную запустить `venue-booking:expire-due`, `venue-booking:dispatch-outbox` и `venue-booking:reconcile-payments` по необходимости.
4. Зависшие записи исправлять только доменной командой. Break-glass требует superadmin, причины и audit trail; прямой SQL допустим лишь по отдельному утверждённому repair script с dry-run и backup.
5. После инцидента сверить booking transitions, payment attempts, webhook receipts и outbox; повторные события безопасны благодаря idempotency keys и уникальным ограничениям.

## Go/no-go checklist

- Миграции и rollback отрепетированы; индексы проверены на production-like объёме.
- E2E request → hold → payment → confirmed → Event, expiry/cancel, whole/half conflicts, IDOR/privacy/webhook suites зелёные.
- Singleton scheduler и workers активны; dashboards и alerts проверены тестовым сигналом.
- Назначены product owner, on-call и DBA; зафиксированы время волны, baseline, результат review и решение.
