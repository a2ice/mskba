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
