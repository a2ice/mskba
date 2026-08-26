# 019 — Rollout, наблюдаемость и E2E

## Цель

Ввести flow аренды поэтапно и обратимо, с измеримыми воротами готовности, миграционным runbook, мониторингом конфликтов и полным набором критических E2E/конкурентных проверок до расширения охвата.

## Статус

Выполнена в `feature/115`.

Rollout поддерживает режимы `internal`, `allowlist` по user/venue/contract, стабильный процент, `all`, `read_only` и master kill switches. `read_only` сохраняет чтение существующих броней при закрытых mutation endpoints. Диагностическая команда агрегирует overdue holds, scheduler/outbox/payment/webhook lag, conflicts и deadlock retries без PII. Конкурентные транзакции используют фиксированный порядок блокировок и ограниченный retry только распознанных deadlock/serialization failures с jitter; доменные ошибки не повторяются. SLO, alerts, волны, stop conditions, migration rehearsal и repair описаны в [runbook](../venue-rental-rollout-runbook.md).

## Доменные изменения

- Новых бизнес-правил не добавлять; зафиксировать production invariants всех предыдущих этапов.
- Определить SLO для создания заявки, решения владельца, hold/expiry и подтверждения.
- Зафиксировать аварийные административные действия и обязательные причины в audit trail.

## Миграции

- Применять expand → deploy/backfill → verify → enable → contract.
- Перед каждым backfill иметь dry-run, счётчики, возобновляемый cursor и процедуру rollback/repair.
- Contract-миграции запускать только после подтверждения отсутствия старых consumers.
- Проверить индексы на production-подобном объёме и время блокировок DDL.

## Handlers и сервисы

- Feature flags по пользователю, площадке/договору и проценту трафика.
- Shadow conflict checker сравнивает новое решение с legacy-данными до включения блокирующей логики.
- Ограниченный retry deadlock/serialization failure с jitter; бизнес-ошибки не ретраить.
- Подготовить команды диагностики, reconciliation и безопасного выключения новых входов.

## Права

- Rollout и аварийные переключатели доступны только superadmin/операционному контуру.
- Support-инструменты не обходят доменные политики без явной break-glass причины.
- Регулярно проверять permission matrix автоматическими тестами и audit-report.

## UI/API

- При выключенном флаге показывать прежний flow или понятное нейтральное состояние.
- Ошибки деградации realtime/payment не должны блокировать чтение текущего статуса.
- Добавить correlation/request id в support-представление без раскрытия внутренних деталей пользователю.
- Rollback UI не должен оставлять ссылки на выключенные endpoints.

## События и jobs

- Метрики: команды по исходу/статусу, conflict rate, hold expiry lag, deadlock/retry, outbox lag, notification failures, webhook/reconcile lag.
- Структурированные логи с booking/venue/contract correlation id без PII и секретов.
- Alerts на зависшие HELD/payment intents, рост конфликтов, scheduler lag и повторные ошибки outbox.
- Scheduler/jobs запускаются singleton там, где требуется, и имеют dashboards/runbook.

## Тесты

- E2E: заявка → решение → hold → оплата → confirmed → Event.
- E2E: coordination без занятия слота; отказ/отмена/expiry освобождают состояние корректно.
- Whole/half-zone матрица конфликтов, параллельные подтверждения, deadlock retry и idempotency.
- Security: IDOR, permission matrix, CSRF, rate limit, webhook signature, приватность переписки/вкладов.
- Regression существующих Event/venue/booking-сценариев.
- Нагрузочные тесты hot venue, owner inbox, availability calendar и scheduler batch.
- Репетиция migration/rollback на production-like копии без персональных данных.

## Обратная совместимость

- Rollout по волнам: internal → выбранные площадки → ограниченный процент → default-on.
- Старые записи читаются до завершения backfill; двойная запись допускается только временно и с reconcile.
- Rollback выключает новые входы, но сохраняет чтение и обслуживание уже созданных броней.
- Удаление legacy-кода — отдельная задача после периода стабильности.

## Критерии приёмки

- Для каждой волны заданы метрики успеха, стоп-условия и ответственный.
- Миграции и rollback отрепетированы, reconciliation не выявляет расхождений.
- Критические E2E, security и concurrency suites проходят стабильно.
- Dashboards и alerts позволяют обнаружить зависший hold, конфликт, deadlock или потерю события до жалобы пользователя.
- Включение default-on выполняется только после документированного go/no-go review.
