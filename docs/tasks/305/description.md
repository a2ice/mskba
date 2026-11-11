# 305 — Защищённые настройки интеграций площадки в Filament

## Цель
Создать закрытую вкладку «Интеграции» у Venue в Filament 5 и глобальный реестр integration configurations. Доступ: superadmin и строго назначенные backoffice permissions.

## Объём
- VenueIntegration: enabled off по умолчанию, name, description, TZ, freshness TTL, horizon days, refresh-on-visit, schedule refresh, mode test/production.
- VenueIntegrationSource: произвольное количество источников (url, external id, enabled, priority, capabilities, status, optional auth, reference to adapter, permission status).
- «Статус разрешения на интеграцию»: `unchecked`, `mskba_allowed`, `testing_only`, `prohibited` — внутреннее административное решение, не юридическое разрешение третьей стороны. Хранить отдельный атрибут проверки допустимого доступа и документировать основание; test mode не должен автоматически включать production.
- При enabled раскрыть настройки, но разрешить сохранять неактивный черновик. Activation gate: обязательные поля + проверка конфигурации.
- Привязки внешнего ресурса к VenueCourt, секреты encrypted и masked, никак не в audit/exports.
- Права `venue.integrations.view/manage/execute/audit`, только global backoffice scope; owner/venue contract НЕ даёт доступ автоматически.
- Неподменяемый журнал действий: чтение (с разумным ограничением), изменения, enable/disable, смена версии, тест, actor/time, безопасный diff; console queue audits отдельно от Eloquent Auditable и без утечек.
- UI по Filament/MSKBA-гайдлайну, responsive.

## Acceptance
Superadmin, delegated permission, venue owner без привилегии, guest, read-only, forced URL, CSRF, SSRF validation, encrypt/masking, audit, draft enable gate, multi sources. PostgreSQL/feature, CI green, merge dev, реальная проверка UI/permissions, приёмка.


## Реализация — первая безопасная фаза (2026-11-12)

**В отдельном PR, до приёмки не считается завершённой.** Добавлены:
`venue_integrations`, `venue_integration_sources`, отдельные привязки к
`VenueCourt`, журнал `venue_integration_audit_events`, модели и
`VenueIntegrationManager` с allowlist-валидацией и обособленными
backoffice permissions (view/manage/execute/audit). Filament на странице
площадки показывает настройки, источники и неизменяемый журнал.

- Все настройки и источники **выключены по умолчанию**. Внешние API/Browser
  нельзя активировать, пока `venue_integrations.live_source_execution_enabled`
  равен `false` (глобальный kill switch).
- Сервис **не делает внешних HTTP/browser вызовов**, не создаёт брони,
  не меняет quote/расписание/цены и не планирует jobs.
- Доступ к редактированию только по глобальным backoffice permissions
  **с проверкой на сервере**; владелец площадки не получает его по
  контракту автоматически. Сквозная Filament-страница Venue также
  требует действующее `backoffice.venue.manage`.
- Ключи сохраняются только в зашифрованном `credentials` и никогда
  не отображаются в формах, JSON-модели или аудитных событиях.
- Адресам разрешены только публичные HTTPS-host без userinfo и secret
  query-параметров; полноценная DNS/IP revalidation требуется при
  фактическом выполнении адаптера в Task 306.
- Статус `mskba_allowed` остаётся **внутренним решением** с ссылкой
  на подтверждение допустимости, а не заменяет ToS третьей стороны.

**Остаток Task 305 после первой фазы:** глобальная админская витрина всех
интеграций, расширенные права/просмотр аудита и запреты по всем
непосредственным Livewire/URL входам, безопасная модель approval для
production, полная браузерная UX-приёмка, отдельный аудит чтения.
Не закрывать задачу по факту успешной миграции.


### Навигация Filament — объединение вкладок (12.11.2026)

Раньше `VenueResource::getRelations()` регистрировал три отдельные вкладки
первого уровня: настройки, источники, журнал. Теперь используется штатный
`RelationGroup::make('Интеграции', [...])` — одна вкладка рядом с остальными
в редакторе площадки. На странице `EditVenue` содержимое группы отображается
через вложенный `Filament\Schemas\Components\Tabs` по действующему
паттерну из `BackofficeUserResource` («Роли и права»):

- **Интеграции → Настройки** — конфигурация площадки;
- **Интеграции → Источники и залы** — источники/привязки;
- **Интеграции → Журнал изменений** — журнал без редактирования.

Второй уровень сохраняется в URL как `integration-section`, независимо от
параметра `relation` первого уровня. Повторно используются существующие
Livewire relation managers, их таблицы, действия и серверные права.
Вкладка «Журнал изменений» по-прежнему доступна только при разрешении
`backoffice.venue.integrations.audit`. Новые таблицы и миграции не нужны.
