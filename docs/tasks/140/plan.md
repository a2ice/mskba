# 140 - План

1. [x] Сверить текущие homepage popup-wizard, Location/Yandex flow, event/tournament filters и существующие predictive/tooltip компоненты.
2. [x] Зафиксировать целевую UX-логику и географическую модель в `description.md`.
3. [x] Добавить `City`/`District`, миграции, связи с `Address`, factories и idempotent initial data для Москвы/Химок.
4. [x] Добавить resolver административных компонентов Yandex -> `city_id`/`district_id` и интегрировать его в address suggest/reverse/create/update flow.
5. [x] Добавить админ-раздел `Города и районы` с CRUD и проверками целостности.
6. [x] Выделить общий navigation/footer contract для homepage popup-wizard и применить к `Мероприятия` и `Площадки`.
7. [x] Пересобрать вкладку `Мероприятия -> Найти`: тип, динамические параметры, tooltip, summary badges.
8. [x] Реализовать location mini-wizard: текущая геопозиция, город, район/округ, метро, улица, площадка и зависимости между ними.
9. [x] Реализовать диапазон дат по умолчанию `сегодня -> +7 дней`.
10. [x] Добавить единый read-only discovery endpoint/read model для Event + Tournament с новыми фильтрами.
11. [x] Реализовать финальный шаг результатов с loading overlay, повторным поиском и возвратом к фильтрам.
12. [x] Обновить продуктовую/техническую документацию.
13. [ ] Выполнить backend-тесты существенной логики, `npm run build` и ручную desktop/mobile проверку. Статический review выполнен; executable checks ожидают PR CI/локальное окружение.
14. [ ] Подготовить изменения к PR и отдельно согласовать PR/merge в `main`.

## Рекомендуемая декомпозиция

- `001-location-directory` — City/District + seed + Address FK — выполнено;
- `002-yandex-location-resolution` — provider/DTO/resolver/backfill — выполнено;
- `003-admin-geography` — административный справочник — выполнено;
- `004-shared-popup-navigation` — общий footer/navigation — выполнено;
- `005-event-type-and-parameters` — шаги Тип/Параметры — выполнено;
- `006-location-mini-wizard` — Где — выполнено;
- `007-date-range` — Когда — выполнено;
- `008-discovery-results` — endpoint/read model + результаты — выполнено;
- `009-docs-and-verification` — документация выполнена; executable verification ожидает CI/ручной smoke-check.
