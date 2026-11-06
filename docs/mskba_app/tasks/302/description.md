# MSKBA App — Task 302: экраны условий аренды, залов и расчёта

## Причина
На staging `APP_THEME=mskba_app`, но после первого PR #613 страницы `venues.booking-policy`, `venues.courts`, `venues.rental-quote` существовали только в `mskba_dark` и в активной теме отсутствовали. Калькулятор и миграции уже доставлены, однако посетитель видел заглушку недостающего шаблона. Исправление — отдельный UI-only PR для активной темы.

## Эталон UI Kit до кода
- `docs/specification/ui-design-system.md` и Filament → Дизайн → Темы оформления → UI Kit.
- Готовые общие компоненты `.panel`, `.button.primary/.secondary`, `.field-group`, `.badge`, `.check-label` (инпут 20×20, зона клика >=44px), `app-account-overview__intro` и `theme::layouts.account`.
- Никаких собственных цветов/шрифтов, локальных inline-стилей, eyebrow и несогласованных модальных окон. Страничный CSS `venue-rental-pricing.css` задаёт только сетки, расстояния и адаптивную компоновку, использует существующие CSS-токены.
- Основные формы завернуты в обычные панели. Цены по датам — одна общая partial для Venue и VenueCourt. Действия на маленьких экранах не перекрываются, таблицы скроллятся внутри своей области.

## Маршруты и состав
1. `/account/venues/{id}/booking-policy`: версия политики, значения для всех залов и календарные overrides площадки.
2. `/account/venues/{identifier}/courts`: настройки отдельных залов, наследование, weekly + calendar overrides, обновление/создание зала в пределах выданных rights.
3. `/venues/{id}/rental`: расчёт аренды с текущим залом; поля длительности и шага обновляются при переключении зала.
4. Контроллеры, модели, права, сценарий quote и история бронирования не дублируются в теме. В теме только представление.

## Проверки
- Test `VenueBookingPolicyQuoteTest::test_active_mskba_app_theme_has_working_venue_and_court_rental_editors` гарантирует доступность трёх маршрутов в `mskba_app`, чтобы не повторить проблему отсутствующего шаблона.
- CI green, PostgreSQL migration check, тесты доступности; ручной QA desktop 1440/768 и mobile 390/360, dark/light, touch, поля, ошибки, длинные заголовки, таблицы.
- Сначала PR → зелёный CI → merge `dev` → успешный deploy → пользовательская визуальная приёмка. Не считать CI заменой визуальной приёмки.
