# 308 — Планировщик актуализации расписаний, TTL и обновление публичного UI

## Цель
Показывать last-known расписание сразу; при истечении TTL и наличии разрешённого активного источника запускать один background job; обновлять страницу без reload по Reverb.

## Объём
- Redis/Queue unique per source+court+date-range, locking, rate limits/budgets, max concurrent runs, backoff, circuit breaker, stale flags.
- По открытию страницы — только enqueue, без внешнего сетевого вызова в web request; periodic prefetch для популярных залов.
- По successful validation atomic commit snapshot + event -> Reverb; fallback last known on errors с явным updated_at/stale UI, не объявлять гарантированно свободным.
- Источник выключен/запрещён/неверифицирован -> no job; несколько источников priority+failover, не мержить слепо расходящиеся статусы.
- Операционные логи: run/correlation, actor/system, duration, slots updated, errors, spent budget; секреты/PII замаскированы.
- Публичный UI desktop/mobile по MSKBA guidelines; существующее создание заявки не ломать.

## Acceptance
Feature integration: 10 одновременных просмотров -> один job, error keeps last snapshot, source disable, cooldown, multiple courts, Reverb payload, expired confidence, bounded requests. CI/dev deploy/ручная приёмка.
