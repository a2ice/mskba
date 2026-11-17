# MSKBA Page Builder — DEV Agent API

## Назначение

Только DEV (APP_ENV=staging, APP_URL=https://dev.mskba.ru). API работает с теми же
page_builder_pages, page_builder_components, page_builder_templates и
page_builder_script_assets, что Filament. Используются штатные
PageBuilderPublisher, PageBuilderMarkup и PageBuilderScriptVersionStore.

API позволяет подключённым доверенным инструментам читать и редактировать
HTML, CSS, JS. Само наличие HTTP API **не создаёт подключение** к ChatGPT:
нужен HTTP-совместимый коннектор/MCP либо авторизованный браузер Work.

## Авторизация

- Filament: Разработка → Доступ агента · Page Builder
  (/backoffice/page-builder-agent-access).
- Подтверждённый superadmin повторно вводит пароль и получает
  Sanctum Personal Access Token на 24 часа, показываемый ровно один раз.
  Все токены агента можно отозвать одной кнопкой.
- Нужен Authorization: Bearer <token>, Accept: application/json.
  Токены **никогда** не передавать в чат, Git или скриншот.
- Принимаются только expiring PersonalAccessToken с префиксом
  mskba-pagebuilder-agent: и abilities page-builder:read,
  page-builder:write, page-builder:publish.
- Права superadmin и все существующие backoffice permissions проверяются
  при каждом запросе. Мобильные токены, сессионные cookies и
  бессрочные токены запрещены.
- Только DEV. В production маршрут закрыт (404 при авторизованном
  запросе; 401 может приходить раньше от auth middleware).

## Методы

Базовый URL: /api/v1/internal/page-builder/{kind}/{key}

kind:
- components — по slug, например venues-scene-v3;
- templates — по slug;
- pages — по ID или ключу home (страница /).

| Метод | Ресурс | Действие |
| --- | --- | --- |
| GET | /{kind}/{key} | Draft и published HTML/CSS/JS, SHA-256, последние revisions |
| PATCH | /{kind}/{key}/draft | Изменить только черновик одного поля |
| POST | /{kind}/{key}/publish | Опубликовать только один field |
| POST | /{kind}/{key}/rollback | Восстановить предыдущую CSS/JS-публикацию |

PATCH JSON: field=html|css|js, value, expected_draft_sha256, reason.

POST publish JSON: field, expected_draft_sha256,
expected_published_sha256, reason.

POST rollback JSON: field=css|js, expected_published_sha256, reason.

Обязательные SHA-256 считываются из свежего GET. Несовпадение возвращает
409 Conflict без записи. Невалидный HTML/CSS — 422. Проверка ресурса
идёт внутри DB-транзакции с lockForUpdate. История содержит
agent_stage / agent_publish / agent_rollback (actor ID, поле, SHA до/после,
причина, ID токена). CSS/JS откат использует штатные previous slots.

Публикация HTML запрещена при посторонних неопубликованных изменениях:
общий publisher не должен вместе с HTML незаметно публиковать CSS, SEO,
путь, Hero Slider settings или template. Для HTML-отката используется
штатный Filament revision workflow. API не содержит SQL/PHP execution,
изменения ролей и управления токенами.

## Сценарий редактирования Arena v3

1. GET /api/v1/internal/page-builder/components/venues-scene-v3.
2. GET /api/v1/internal/page-builder/pages/home.
3. Сохранить текущий снимок, сделать минимальный diff, PATCH конкретного
   draft с expected_draft_sha256.
4. Повторный GET — сверить изменение и SHA.
5. Проверить превью и явно POST publish с обоими SHA.
6. Перезагрузить страницу, проверить мяч и слои в 1440/1920px,
   затем tablet/mobile. При регрессии вызвать rollback CSS/JS.

Установщик Arena v3 (mskba:venues-arena-v3:desktop --check/--apply)
больше не вызывается автоматически в deploy_dev: он неоднократно
конфликтовал с editor-owned Filament-состоянием.

## Подключение к ChatGPT

Для прямой записи из чата необходим установленный HTTP/MCP-коннектор,
который поддерживает передачу Bearer-токена по HTTPS. Токен вводится
**только в настройках этого коннектора**, не в сообщении ChatGPT.
Без такого инструмента API можно использовать из Work через подключённый
облачный браузер или другой разрешённый HTTP-клиент. Не утверждать,
что возможность прямого вызова уже появилась автоматически.

## GitHub Actions-мост

В режиме без Work используется отдельный workflow
`.github/workflows/dev-page-builder-api-bridge.yml`.
Ассистент создаёт JSON-задания в `ops/page-builder/requests/`,
после review и слияния в `dev` workflow обращается к этому API
с токеном из environment secret `MSKBA_PAGE_BUILDER_AGENT_TOKEN`.
Секрет не хранится в Git. Подробнее о формате заданий, контроле SHA-256
и восстановлении предыдущих редакций: [инструкция по мосту](../../ops/page-builder/README.md).

Для этой интеграции требуется **новый** токен из Filament: переданный ранее
в переписку необходимо отозвать. Выдаваемые токены действуют 24 часа.
