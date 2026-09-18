# Task 196 — Admin acquisition campaigns, QR flyers and template/export foundation

## Оригинальное описание

Довести acquisition-контур Task 191 до операционного состояния: администратор должен создавать кампании для QR-листовок и других каналов, привязывать их к площадкам, получать QR/листовку и видеть базовую аналитику.

Параллельно заложить общий шаблонный/export-контракт: первая A4-листовка пока является hardcoded HTML, но в дальнейшем шаблоны должны храниться отдельно, поддерживать безопасные плейсхолдеры и использоваться не только для листовок, но и для рассылок.

## Подробное описание

### Acquisition admin

Новый раздел `/admin/acquisition` управляет существующей моделью `AcquisitionCampaign` из Task 191.

Администратор может:

- просматривать и фильтровать кампании;
- создавать/редактировать название, public code, канал, площадку, радиус геопроверки, период активности и статус;
- выбирать площадку через общий predictive-search pattern;
- указывать подпись физического места размещения и внутреннюю заметку в `metadata`;
- открывать готовый `/join/{public_code}`;
- получать QR в SVG/PNG;
- просматривать A4 HTML-preview и скачивать PDF;
- видеть total visits, связанные визиты/уникальных пользователей и статусы геопроверки.

Одна площадка может иметь несколько QR-кампаний: например стенд, вход и раздаточная листовка. `channel=qr` остаётся общим маркетинговым каналом, а конкретная физическая точка определяется campaign.

### Шаблоны и документы

Вводится общий контракт:

1. `TemplateRenderer` получает зарегистрированный template key и context, возвращает HTML.
2. Текущая реализация использует только whitelisted Blade-view из config. Произвольное имя Blade-view извне не принимается.
3. `DocumentRenderer` преобразует HTML в конкретный формат.
4. Первый output — PDF через Gotenberg/Chromium.
5. DOCX фиксируется как будущий формат, но не имитируется в первой версии.

Хранимые редактируемые шаблоны в будущем **не должны** выполняться как Blade/PHP. Для них нужен отдельный безопасный placeholder renderer с whitelist контекста. Тот же слой сможет обслуживать flyer, email и другие сообщения.

### PDF runtime

HTML→PDF выполняет отдельный Gotenberg service. Это оставляет Chromium вне PHP-container и даёт современный CSS для печатных шаблонов. LibreOffice converter сейчас не нужен; он остаётся подходящим вторым renderer для будущих office-форматов.

QR генерируется локально через `qrencode` внутри app-container и встраивается в flyer как data URI; Gotenberg не зависит от публичного URL приложения.

## Проверки

- feature coverage CRUD/permissions и acquisition statistics;
- HTTP fake для Gotenberg boundary;
- route/view smoke;
- production compose validation;
- полный CI: PHP tests + frontend build.

## Статус

- [x] архитектура;
- [ ] admin CRUD;
- [ ] analytics;
- [ ] QR export;
- [ ] flyer HTML/PDF;
- [ ] template/document contracts;
- [ ] documentation;
- [ ] CI / merge.
