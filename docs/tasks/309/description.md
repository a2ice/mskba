# 309 — Пилот BASKETPOINT / FindSport и контроль качества источников

## Цель
Конфигурировать BASKETPOINT (Дубнинская) и страницу findsport.ru/playground/4805 как эталон multi-source/source-mapping; добиться сравнимой нормализации расписания и цен в рамках допустимого режима доступа.

## Объём
- Source metadata: FindSport external id 4805, URL, timezone Europe/Moscow, 30-min grid, 60-min min booking, local MSKBA permission status `mskba_allowed` for agreed tests, execution mode test first, audit.
- Проверить точный тип загрузки слотов и правила серых/прошедших/недоступных ячеек. Не считать отсутствие кнопки booked без проверки.
- Реализовать scenario/config fixtures для адаптера и автоматизированные контрактные проверки по сохранённым разрешённым fixture (без фактического внешнего бронирования). Настоящий browser polling — только если способ автоматизированного доступа допустим/согласован с внешним сервисом; внутреннее поле `mskba_allowed` не отменяет ToS стороннего сайта.
- Параллельно проверить второй разрешённый тестовый источник для оценки универсальности и fallback, не полагаться на единственный агрегатор.
- Сравнить внешние slot prices и калькулятор MSKBA, min duration, альтернативный источник, health и стоимость запусков. Без реальных броней/платежей.

## Acceptance
Documented test harness fixture->JSON->snapshot->UI, negative/unknown slots, price by half-hour, disabled source, permission logs; разрешённый live smoke если применим. CI green, merge dev и ручная приёмка.
