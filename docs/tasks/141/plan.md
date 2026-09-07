# 141 — План

1. [x] Восстановить актуальный `main` после merge Task 140 и проверить замечания PR review.
2. [x] Зафиксировать scope и решения follow-up hotfix.
3. [ ] Добавить production deploy вызов только `GeographySeeder` после миграций.
4. [ ] Исправить пересечение дат для явно завершённых турниров в homepage discovery.
5. [ ] Передавать city/street ограничения homepage в `venues.search` и применять их до `limit`.
6. [ ] Добавить regression tests для tournament close и venue geography search.
7. [ ] Обновить task status/docs по результату реализации.
8. [ ] Создать PR, дождаться зелёного CI и отдельно согласовать merge в `main`.

## Проверка

- `php artisan test` через штатный PR CI;
- `npm run build` через штатный PR CI;
- после merge production deploy должен выполнить `migrate --force`, затем только `GeographySeeder`;
- ручной smoke-check homepage location/venue selector на production.