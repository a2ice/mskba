# 140 - План

1. [x] Сверить текущие homepage popup-wizard, Location/Yandex flow, event/tournament filters и существующие predictive/tooltip компоненты.
2. [x] Зафиксировать целевую UX-логику и географическую модель в `description.md`.
3. [ ] Добавить `City`/`District`, миграции, связи с `Address`, factories и idempotent initial data для Москвы/Химок.
4. [ ] Добавить resolver административных компонентов Yandex -> `city_id`/`district_id` и интегрировать его в address suggest/reverse/create/update flow.
5. [ ] Добавить админ-раздел `Города и районы` с CRUD и проверками целостности.
6. [ ] Выделить общий navigation/footer contract для homepage popup-wizard и применить к `Мероприятия` и `Площадки`.
7. [ ] Пересобрать вкладку `Мероприятия -> Найти`: тип, динамические параметры, tooltip, summary badges.
8. [ ] Реализовать location mini-wizard: текущая геопозиция, город, район/округ, метро, улица, площадка и зависимости между ними.
9. [ ] Реализовать диапазон дат по умолчанию `сегодня -> +7 дней`.
10. [ ] Добавить единый read-only discovery endpoint/read model для Event + Tournament с новыми фильтрами.
11. [ ] Реализовать финальный шаг результатов с loading overlay, повторным поиском и возвратом к фильтрам.
12. [ ] Обновить продуктовую/техническую документацию.
13. [ ] Выполнить backend-тесты существенной логики, `npm run build` и ручную desktop/mobile проверку.
14. [ ] Подготовить изменения к коммиту и отдельно согласовать commit/PR/merge.

## Рекомендуемая декомпозиция

- `001-location-directory` — City/District + seed + Address FK;
- `002-yandex-location-resolution` — provider/DTO/resolver/backfill;
- `003-admin-geography` — административный справочник;
- `004-shared-popup-navigation` — общий footer/navigation;
- `005-event-type-and-parameters` — шаги Тип/Параметры;
- `006-location-mini-wizard` — Где;
- `007-date-range` — Когда;
- `008-discovery-results` — endpoint/read model + результаты;
- `009-docs-and-verification` — документация и итоговые проверки.
