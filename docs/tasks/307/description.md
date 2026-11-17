# 307 — Нормализация внешней занятости и цен, сохранение snapshots

## Цель
Хранить отдельным слоем внешний снимок доступности, не перезаписывая VenueSchedule (часы работы) и регулярные цены.

## Объём
- ExternalAvailabilitySnapshot (source, venue, court, observed_at, coverage, complete, timezone, parser_version, checksum, raw redacted diagnostic) и ExternalAvailabilitySlot (starts_at, ends_at, status, price_minor/currency, price_unit_minutes).
- status available/booked/unavailable/unknown, unknown не становится доступным; coverage complete и atomic replace только успешно проверенных интервалов.
- Нормализация 30-minute grid, min rent >=60min, variable tariff, date overrides, DST, currency/minor units, max duration nullable.
- Приоритет источник/ручное исключение, проверка конфликтов двух источников одной физической зоны, freshness, orphaned date, missing steps, old snapshot fallback.
- Проверка и цена MSKBA не равны внешнему «окончательно подтверждён»: no reservation claim.
- Интеграционные DTO/Service и тестовые fixtures BASKETPOINT: 1625 за 30 мин, 3250 за 30 мин, 1 час за 6500 (только fixture, без обращения к источнику).

## Acceptance
Unit/PostgreSQL: mapping, complete/partial/error, no data loss, dedup, stale, race, multiple courts, priority, TZ, totals, 60min minimum, correct provenance. UI просмотра snapshot в Filament. CI green, merge dev, приёмка.
