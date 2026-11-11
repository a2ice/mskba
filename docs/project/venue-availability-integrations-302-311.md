# Программа 302–311: информация о залах, расписаниях и стоимости

**Дата фиксации:** 2026-11-12. **Ветка:** dev first, main only after explicit acceptance.
**Исходный аудит:** [2026-11-12-backlog-audit](../reports/2026-11-12-backlog-audit.md).

## Продуктовый инвариант

Разделить три независимых понятия:

1. **Расписание работы** `VenueSchedule` / `VenueCourtSchedule`
   отвечает, в какие периоды зал *может работать*, а не гарантирует бронь.
2. **Занятость и цена источника** — наблюдаемые извне данные с
   `source_id`, временем получения, TTL, полнотой покрытия и статусом
   `available/booked/unavailable/unknown`. Отсутствие слота ≠ свободно.
3. **Бронирование MSKBA** и **фактически полученная оплата** — отдельные
   бизнес-факты. Подтверждённая внешним ответственным бронь не доказывает
   поступление денег.

**Ни один адаптер сам не меняет цену опубликованной VenueBookingPolicy,
не создаёт внешнюю бронь и не подтверждает платёж.**

## Что уже в коде

| № | Существующая реализация | Главные критерии оставшейся приёмки |
|---|---|---|
| 302 | `EffectiveCourtPolicy`, `VenueSlotPricing`, `VenueCourtSlotPrice`, `VenueCourtCalendarSlotPrice`, `VenueCalendarSlotPrice`; `QuoteVenueBookingHandler` | Судить по конкретному залу; наследование null/0, несколько получасовок, календарные исключения, TZ/DST, Filament+публичный UI |
| 303 | `VenuePricingRuleEvaluator`, `VenuePricingRuleManager`, `PricingRulesRelationManager`, published/draft rules | Приоритет/stop-processing, 120→30%, immutable snapshots, проверка условий в UI |
| 304 | `VenueCommissionCalculator` + `VenueCommissionManager`, Filament `Commissions`, global/venue/court | Сверить экономического агента с новой проектной моделью PR #622: текущий selected_agent_id **не равен** предлагаемому эксклюзивному агентскому контракту; это blocker полной приёмки |

Существующие тесты: `VenueCourtInheritedPolicyAccessTest`,
`VenuePricingRulesTest`, `VenueCommissionPricingTest`,
`VenuePricingRulesConsoleTest`, `VenueCourtBookingIsolationTest`.
Их наличие **не заменяет** новую e2e-матрицу реального зала.

## Очерёдность реализации и gates

| Этап | Что выпускаем | Условие перехода |
|---|---|---|
| A0 (302–304) | Сверка фактической реализации, тесты price breakdown и закрытие замечаний | Dev acceptance для двух разных залов одной площадки, 0/null, overrides, цена=итог; решение по контракту агента |
| A1 (305) | Закрытый реестр VenueIntegration/Source, статус допустимого доступа, court mappings, encrypted tokens, audit, отдельные permissions. `enabled=false` по умолчанию | Superadmin/delegated/owner/guest tests; защита SSRF, секреты не в логах; никто не может включить источник без проверки |
| A2 (306) | Адаптерные контракты и **локальные fixtures** read-only API / browser / manual. Нет createBooking/cancelBooking side effects | Forbidden hosts, invalid JSON, latency, retries, schema contract, idempotency |
| A3 (307) | Immutable источник→snapshot→слоты, completeness, UTC/TZ, provenance, stale/unknown, conflict policy | Полный/частичный/ошибочный снимок; нет потери last-known; по залам/времени различаются значения |
| A4 (308) | Unique job, bounded TTL/refresh, rate limit, circuit breaker, UI last-known/stale + realtime | Одновременные запросы → не более одного job, disabled source → ноль calls; ошибки → старый snapshot |
| A5 (309) | Пилот BASKETPOINT/FindSport на fixtures и второй независимый тестовый источник | Только после проверки правомерности live access: ручная валидация состояния слотов и сравнение цен с quote |
| A6 (310) | Ручное оформление внешней брони, комментарии, отдельный fulfillment status, разрешённый external_manual settlement | Подтверждение без оплаты, отказ, конфликт, права и аудит, никаких внешних createBooking API |
| A7 (311) | Учёт фактических внепортальных платежей/остатка/сроков/напоминаний только opt-in | Частичная/полная оплата, позднее подтверждение, права, dedup уведомлений; без фиктивных Wallet платежей |

Каждый этап: **feature branch → targeted + PG16/17 + full CI → dev deploy
→ ручной smoke и скриншоты desktop/mobile → пользовательская приёмка**.
Не объединять 305–311 в одну неоткатываемую миграцию; необратимые
операции и реальные брони/списания запрещены до отдельного включения.

## Фикстуры, обязательные для запуска пилота

1. **Школа №1794:** бесплатный outdoor без подтверждения и платный
   indoor с подтверждением. Результат поиска, выбранный зал, quote и UI
   должны ссылаться на один и тот же court.
2. **BASKETPOINT (Дубнинская) — только офлайн fixture** из Task 307/309:
   1625 ₽/30 мин, 3250 ₽/30 мин, 6500 ₽/60 мин. Это тестовые входы,
   **не утверждение реальной текущей цены внешнего поставщика**.
   Нужны границы слотов, минимум аренды 60 мин и контролируемая валюта.
3. Наследование тарифа whole/half на два зала, явный 0/NULL,
   календарная цена в выходные/праздник, несколько неодинаковых шагов.
4. Состояние `unknown` и пропуск интервалов никогда не выводить
   как свободные; ошибка импорта не удаляет предыдущие данные.
5. Два источника дают разную занятость: фиксировать конфликт, provenance,
   не объединять слоты слепо и не выдавать гарантию.
6. External manual: подтверждённая бронь 6500 ₽ с обещанием оплатить
   завтра, позже 2000+4500 ₽ (сценарий 311), без изменения состояния
   брони и без имитации поступления денег на счёт MSKBA.

## Безопасность и внешний доступ

- Указанный в Task 309 внутренний `mskba_allowed` **не является**
  юридическим согласием внешнего сервиса. До реального polling необходимо
  проверить договор, ToS, допустимый способ доступа, ограничения нагрузки
  и право на использование цен; источник оставлять `testing_only`
  либо выключенным при сомнениях.
- Host allow-list, DNS/IP revalidation (SSRF), HTTPS by default, секреты
  только encrypted, различать read-only и разрешённые future methods.
- Никакого auto booking, auto payment, фиктивных статусов и оптимистичной
  трактовки `unknown`.
- Реальные цены/наличие нельзя считать проверенными без timestamp,
  источника и ручного сравнения с поставщиком.

## Перед main/production

Формальное решение о релизе принимается по evidence: green PG16/17,
сохранность старых данных и rollback, права и аудит, реальные 2–3 зала,
UI desktop+mobile, стоимость refresh и rate limits, отсутствие
неразрешённых внешних запросов, соглашение по ответственности за
подтверждение брони и поступление денег. На `dev` сначала можно
проверять только безопасные fixtures/ручные источники.
