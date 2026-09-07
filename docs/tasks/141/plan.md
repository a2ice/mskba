# 141 — План

1. [x] Восстановить актуальный `main` после merge Task 140 и проверить замечания PR review.
2. [x] Зафиксировать scope и решения follow-up hotfix.
3. [x] Добавить production deploy вызов только `GeographySeeder` после миграций.
4. [x] Исправить пересечение дат для явно завершённых турниров в homepage discovery.
5. [x] Передавать city/street ограничения homepage в `venues.search` и применять их до `limit`.
6. [x] Добавить regression tests для tournament close и venue geography search.
7. [x] Зафиксировать `Назад` слева и `Далее / Готово` справа независимо от `Пропустить`, смягчить event-wizard tooltip trigger до нейтрального серого.
8. [x] Обновить task status/docs по результату реализации.
9. [ ] Создать PR, дождаться зелёного CI и отдельно согласовать merge в `main`.

## Проверка

- regression tests добавлены, executable result ожидается в штатном PR CI;
- `php artisan test` через штатный PR CI;
- `npm run build` через штатный PR CI;
- после merge production deploy должен выполнить `migrate --force`, затем только `GeographySeeder`;
- ручной smoke-check homepage location/venue selector на production после merge;
- desktop/mobile: footer actions находятся на противоположных краях, tooltip question-mark остаётся нейтрально-серым в normal/hover/focus.

## Реализовано

- production deploy после миграций запускает только idempotent `Database\\Seeders\\GeographySeeder`, а не общий `DatabaseSeeder`;
- homepage discovery учитывает `tournament_closed_at` как дополнительную фактическую границу окончания турнира;
- `venues.search` принимает `city`/`street`, `VenueSearchCache` индексирует структурированный адрес, а `SearchVenuesHandler` применяет ограничения до сортировки и `limit`;
- оба frontend selector-а (`venue-selector.js`, `venue-selector-metro.js`) передают существующие homepage city/street dataset-фильтры и в predictive lookup, и в map lookup;
- shared wizard footer явно фиксирует grid-column для back/skip/next, поэтому скрытый skip не сдвигает правую action в центр;
- question-tooltip внутри event wizard получает локальный светло-серый border/background/text без изменения глобального tooltip-компонента;
- добавлены регрессии на closed continuous tournament и на площадку, которая без server-side geography была бы вытеснена за `limit`.