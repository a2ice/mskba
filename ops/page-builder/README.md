# Page Builder · GitHub Actions → DEV API

Мост позволяет делать точечные изменения HTML/CSS/JS в опубликованном Filament
через существующий защищённый API без копирования больших исходников в чат.
Источник истины — база Filament на DEV. Мост не меняет production.

## Настройка (однократно)

1. Filament DEV → Разработка → Доступ агента · Page Builder.
   Отзови токен, ранее оказавшийся в чате, и выпусти новый.
2. GitHub → a2ice/mskba → Settings → Environments → development →
   Environment secrets → New secret.
   Имя: MSKBA_PAGE_BUILDER_AGENT_TOKEN.
   Значение: НОВЫЙ токен, скопированный прямо из Filament.
3. Не отправляй токен в чат, issue, PR, workflow input или файл Git.
   Токен действует 24 часа. Для дальнейшей работы обновляй environment
   secret после перевыпуска в Filament. Прежний токен лучше сразу отзывать.
4. Репозиторий публичный: в JSON-заданиях не должно быть
   приватных данных, токенов, паролей или конфиденциальных черновиков.

## Workflow

.github/workflows/dev-page-builder-api-bridge.yml

Запускается только при push/merge в ветку dev, если изменён один файл
в папке ops/page-builder/requests/ с расширением .json.

Отдельный workflow. Не вызывает deploy_dev, SSH, SQL или PHP.
Использует GitHub environment development, где хранится секрет.
На production не обращается, HTTP origin жёстко задан как
https://dev.mskba.ru.

## Сценарий

1. Ассистент через GitHub создаёт JSON-задание inspect в отдельной ветке;
   PR → зелёный CI → merge в dev.
2. Workflow делает GET защищённого API, печатает только SHA-256, размеры
   и статус черновика/публикации; исходники HTML/CSS/JS не печатаются
   в публичных логах.
3. По известным точным фрагментам создаётся задание apply с полем
   field (html/css/js), ожидаемыми SHA-256 черновика и публикации,
   необязательным SHA-256 результата и набором замен replace_once.
   Каждая замена должна совпасть ровно один раз.
4. После merge workflow получает текущие данные напрямую из Filament.
   При совпадении всех хэшей сохраняет черновик PATCH; если явно указано
   publish=true, выполняет отдельную публикацию POST и проверяет
   новый SHA-256 повторным GET.
5. При дефекте отдельным заданием rollback восстанавливаем предыдущую
   опубликованную версию CSS или JS.

Уникальное имя задания рекомендуется формировать по номеру задачи и версии.
Один JSON-файл — одна сущность, одно поле и одна операция.
Автоматический откат нескольких полей не поддерживается:
обновления CSS и JS следует делать последовательно и проверять каждое.

## JSON: inspect

Пример: ops/page-builder/examples/inspect-venues-scene-v3.json.

Скопировать его в ops/page-builder/requests/<уникальное-имя>.json
в ветке PR. Для страницы home нужно kind=pages, key=home.

## JSON: apply

Поля:

- version: 1
- environment: development
- confirm: DEV_ONLY_PAGE_BUILDER
- operation: apply
- kind: components | pages | templates
- key: venues-scene-v3 (либо home для главной)
- field: html | css | js
- reason: понятная причина изменения для аудита
- publish: true | false
- expected_draft_sha256: актуальный SHA-256 черновика
- expected_published_sha256: актуальный SHA-256 публикации
- expected_result_sha256: необязательный SHA-256 полного текста ПОСЛЕ изменения
- edits: массив объектов вида {type: "replace_once", from: "...", to: "..."}.

Пример чернового manifest:

    {
      "version": 1,
      "environment": "development",
      "confirm": "DEV_ONLY_PAGE_BUILDER",
      "operation": "apply",
      "kind": "components",
      "key": "venues-scene-v3",
      "field": "css",
      "reason": "Изменение позиции игрока на desktop",
      "publish": false,
      "expected_draft_sha256": "<актуальный SHA-256>",
      "expected_published_sha256": "<актуальный SHA-256>",
      "expected_result_sha256": "<хэш конечного текста>",
      "edits": [
        {"type": "replace_once", "from": "точный старый CSS", "to": "новый CSS"}
      ]
    }

Если полный исходный CSS/JS недоступен ассистенту, поле
expected_result_sha256 можно опустить: runner вычислит его сам и
повторно проверит после записи. Тогда повторный запуск уже применённого
задания откажет по изменившемуся исходному SHA, не сделав повторную запись.

Тестовый JSON выше НЕ готов к запуску: значения SHA и CSS должны
соответствовать содержимому Filament. Для публикации HTML дополнительно
необходим confirm_html_publish=PUBLISH_HTML_EXPLICITLY.

## JSON: rollback

Включает те же version/environment/confirm/kind/key/reason,
operation=rollback, field=css|js,
expected_published_sha256=актуальный SHA-256 публикации.

## Проверки безопасности

- Ошибка SHA-256 → остановка без записи.
- Неверное число вхождений from → остановка.
- Поле/сущность не разрешены или попытка произвольного URL → остановка.
- Токен отсутствует/просрочен → отказ.
- JSON не проходит предварительную проверку → запроса PATCH нет.
- Повторный запуск опубликованного задания не создаёт вторую публикацию.
- Если PATCH успешен, но POST publish не удался, черновик может сохраниться:
  опубликованная версия не считается изменённой до проверки.
- API не выводит в Actions logs полный код и не хранит секрет в Git.

Unit tests:
node --test tests/JavaScript/page-builder-agent-bridge.test.mjs

Полный API-контракт: docs/specification/page-builder-agent-api.md.

## Publication of a staged draft (publish-only)

If `PATCH /draft` has succeeded but publication stopped, do **not** run
another replace operation over the pending draft. Inspect current
`draft_sha256` and `published_sha256`, then create a new reviewed request:

    {
      "version": 1,
      "environment": "development",
      "confirm": "DEV_ONLY_PAGE_BUILDER",
      "operation": "publish",
      "kind": "components",
      "key": "venues-scene-v3",
      "field": "css",
      "reason": "Публикация уже проверенного черновика",
      "expected_draft_sha256": "<SHA-256 from read-only inspection>",
      "expected_published_sha256": "<published SHA-256 from inspection>"
    }

This operation does not upload or edit text: it publishes the existing draft
only after both SHA-256 values match. For HTML it additionally requires
`confirm_html_publish=PUBLISH_HTML_EXPLICITLY`.

Laravel's `TrimStrings` middleware strips outer whitespace from incoming
JSON source fields. The bridge now normalizes the outer whitespace **before**
calculating the proposed SHA and before PATCH; internal formatting stays intact.
The `expected_result_sha256` for future `apply` requests must match that
normalized value (or be omitted so the runner computes it).
