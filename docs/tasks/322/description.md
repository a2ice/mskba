# 322 — Персональные условия аренды и PUBLIC/USER_BOUND купоны

## Оригинальное описание

Реализовать одобренную модель персональных условий и купонов в текущем чистом
worktree. Не менять main/production, не читать секреты, не коммитить и не
публиковать. Приоритет — работоспособный B/C с управлением Filament/аккаунт,
сохранением quote и server-side проверками. Связано с 299/300.

## План и границы

Расширить VenueGroupOffer вместо второй модели скидок. Один финальный этап
VenuePersonalPricing после прежних тарифов и комиссий. Управление и preview,
атомарное списание в request handler, безопасная публичная проекция. Новый
расчёт выключен по умолчанию до CI и review. Агентскую модель не мигрировать.

## Результат

Локальная реализация частичного сквозного среза. Точные контракты, включение,
сроки, авторизация, округление и известные пробелы:
[спецификация специальных условий](../../specification/venue-special-terms.md).
UI-след: [MSKBA App 657](../../mskba_app/tasks/657/description.md).

Ключевые файлы: `VenueSpecialTermsManager`, `VenuePersonalPricing`,
`QuoteVenueBookingHandler`, `RequestVenueBookingHandler`,
`VenueSpecialTermsController`, `SpecialTermsRelationManager`, миграция
`2026_11_16_120000_extend_venue_offers_for_personal_pricing.php`, Blade обеих тем.

## Проверка

Новый `VenueSpecialTermsTest`: права/валидация, PUBLIC/USER_BOUND, утечка кода,
даты/окна/дни недели/venue/court/scope/currency, лимиты, best non-stackable,
целочисленное округление, immutable snapshot, retry/последняя квота, preview,
экранирование и отсутствие публичной разбивки комиссий. Обновлены ожидания
публичной проекции в существующих тестах, внутренняя арифметика сохранена.

Фактическая проверка на изолированной копии текущих исходников в `/tmp`,
с зависимостями соседнего checkout только для чтения (оба lock-файла совпадают),
без копирования environment-файлов:

- **118 feature tests / 760 assertions — PASS**, включая 20 новых specials tests,
  комиссионные расчёты, quote/policy, групповые предложения, внешние snapshots,
  права контрактов, account editor и Filament access/policy editor.
- **10 JS tests — PASS**, включая персональную цену/безопасную причину в preview.
- **22 PHP files lint — PASS**, `git diff --check` и Node syntax — PASS.
- Сборка обеих затронутых тем **PASS** в scratch-копии с временным Vite config,
  включающим только entry points этих тем. Полная сборка blocked: в доступном
  `node_modules` отсутствует `swagger-ui-dist`; исходники этого пакета не менялись.
- Более широкий прогон VenueBooking + group offers + external snapshots:
  **151/154 PASS**, три падения воспроизведены отдельным запуском на исходном HEAD:
  `VenueBookingLifecycleTest::test_same_half_and_legacy_pending_block_hold`,
  `VenueBookingProjectionTest::test_owner_inbox_shows_complete_interval_and_only_requested_bookings_are_actionable`,
  `VenueCourtInheritedPolicyAccessTest::test_inherited_court_price_can_be_overridden_and_quotes_freeze_price_snapshots`.
  Они не исправлялись как несвязанные с текущим срезом.

CI — обязательный финальный gate. Нужны PostgreSQL concurrency test и визуальная приёмка
desktop/mobile, фокус/клавиатура. Без них не включать флаг и не считать A–F
полностью выполненными. Commit/PR выполняет supervisor после review.
