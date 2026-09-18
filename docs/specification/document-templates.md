# Document templates and rendering

## Оглавление

- [Назначение](#назначение)
- [Контракты](#контракты)
- [Встроенные шаблоны](#встроенные-шаблоны)
- [PDF](#pdf)
- [Будущие редактируемые шаблоны](#будущие-редактируемые-шаблоны)
- [Будущие форматы](#будущие-форматы)

## Назначение

Модуль `Template` отделяет содержимое шаблона от способа выпуска документа. Первый consumer — A4-листовка acquisition-кампании, но контракт рассчитан и на будущие email/notification templates.

## Контракты

`TemplateRenderer` принимает стабильный template key и whitelist context и возвращает HTML.

`DocumentRenderer` принимает уже сформированный HTML и целевой формат и возвращает бинарный документ вместе с MIME type.

Такое разделение не связывает будущий редактор шаблонов с конкретной PDF-библиотекой и позволяет независимо добавлять новые каналы вывода.

## Встроенные шаблоны

Первая версия использует trusted Blade-view из репозитория. Разрешённые template keys регистрируются в `config/document-templates.php`; произвольное имя Blade-view из HTTP request или базы не исполняется.

Текущий встроенный шаблон:

- `acquisition.flyer.a4` — A4-листовка для acquisition campaign.

Контекст листовки содержит campaign, optional venue, placement, canonical join URL, QR data URI и logo data URI.

## PDF

HTML→PDF выполняется отдельным Gotenberg/Chromium service.

Причины:

- шаблон уже является HTML/CSS, поэтому промежуточный DOCX/LibreOffice не нужен;
- Chromium даёт предсказуемую поддержку современного CSS и `@page`;
- браузерный runtime не добавляется в PHP image;
- один и тот же HTML используется для browser preview и PDF.

Gotenberg доступен приложению по `GOTENBERG_URL` (по умолчанию `http://gotenberg:3000`). Используется официальный `8-chromium` variant без LibreOffice; endpoint не публикуется наружу. Если позже появится реальный DOCX/office flow, можно перейти на full Gotenberg image или подключить отдельный office renderer без изменения `TemplateRenderer`.

QR генерируется локально утилитой `qrencode` в app image. Для PDF QR передаётся как data URI, поэтому Gotenberg не должен обращаться назад к публичному сайту.

## Будущие редактируемые шаблоны

Шаблоны, которые в будущем будут редактироваться через админку и храниться в БД, **не должны** исполняться как Blade/PHP.

Для них нужен отдельный safe placeholder renderer:

- whitelist доступных переменных;
- отсутствие произвольного PHP/Blade;
- экранирование по умолчанию;
- явные helper/filter операции;
- versioning шаблона;
- preview с тестовым context;
- отдельный тип/канал: flyer, email и другие сообщения.

Тот же template key/renderer contract должен использоваться для листовок и будущих рассылок, чтобы не создавать независимые несовместимые редакторы.

## Будущие форматы

`PDF` реализован первым.

`DOCX` зарезервирован в формате документа, но renderer намеренно не имитирует его поддержку. Когда появится реальный сценарий редактируемого office-документа, можно добавить отдельный renderer. LibreOffice sidecar уместен именно на этом этапе для DOCX/ODT/XLSX↔PDF, а не как обязательный слой HTML→PDF.
