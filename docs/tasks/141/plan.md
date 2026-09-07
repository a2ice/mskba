# 141 — План

1. [x] Восстановить актуальный `main` после merge Task 140 и проверить замечания PR review.
2. [x] Зафиксировать scope и решения follow-up hotfix.
3. [x] Добавить production deploy вызов только `GeographySeeder` после миграций.
4. [x] Исправить пересечение дат для явно завершённых турниров в homepage discovery.
5. [x] Передавать city/street ограничения homepage в `venues.search` и применять их до `limit`.
6. [x] Добавить regression tests для tournament close и venue geography search.
7. [x] Обновить task status/docs по результату реализации.
8. [ ] Создать PR, дождаться зелёного CI и отдельно согласовать merge в `main`.

## Проверка

- regression tests добавлены, executable result ожидается в штатном PR CI;
- `php artisan test` через штатный PR CI;
- `npm run build` через штатный PR CI;
- после merge production deploy должен выполнить `migrate --force`, затем только `GeographySeeder`;
- ручной smoke-check homepage location/venue selector на production после merge.

## Реализовано

- production deploy после миграций запускает только idempotent `Database\\Seeders\\GeographySeeder`, а не общий `DatabaseSeeder`;
- homepage discovery учитывает `tournament_closed_at` как дополнительную фактическую границу окончания турнира;
- `venues.search` принимает `city`/`street`, `VenueSearchCache` индексирует структурированный адрес, а `SearchVenuesHandler` применяет ограничения до сортировки и `limit`;
- оба frontend selector-а (`venue-selector.js`, `venue-selector-metro.js`) передают существующие homepage city/street dataset-фильтры и в predictive lookup, и в map lookup;
- добавлены регрессии на closed continuous tournament и на площадку, которая без server-side geography была бы вытеснена за `limit`.