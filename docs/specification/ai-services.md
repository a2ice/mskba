# AI services

## Назначение

`App\Modules\Ai` — внутренний integration boundary для внешних AI-провайдеров.
Предметные контексты не должны вызывать OpenAI или другой provider напрямую из
контроллера, Blade или frontend. Identity передаёт нормализованный предметный payload
через `PlayerCharacterAiGateway`.

`NullPlayerCharacterAiGateway` остаётся безопасным fallback и возвращает
стабильную ошибку `ai_not_configured`, если ни один provider не настроен.

## Player Character

Контракт покрывает два действия:

- проверка фото лица по ожидаемому ракурсу `front|left|right`;
- генерация 2D-представления персонажа по подтверждённым face references и параметрам игрока.

### Фото лица

Порядок обязателен:

1. HTTP-валидация формата/размера;
2. нормализация изображения в памяти до WebP, максимум 512 px по большей стороне;
3. AI-проверка наличия пригодного лица и соответствия ожидаемому ракурсу;
4. только после успешной AI-проверки — запись private-файла и `Media`;
5. старая подтверждённая версия удаляется только после успешного сохранения новой.

Если AI недоступен, запрос отклонён, ракурс неверный или проверка завершилась ошибкой,
новый файл и `Media` не создаются. Уже сохранённый подтверждённый reference не меняется.

Frontend при выборе файла может сразу показывать локальный object URL как оптимистичный preview.
Такой preview не является сохранённым reference: до ответа AI он отображается с loading overlay,
а backend остаётся единственным источником истины. Подтверждённые приватные face references
отдаются только владельцу через authenticated preview endpoint и не кэшируются браузером.

Подтверждённые записи маркируются
`source_reference=player-character-ai-validated-v1`. Старые технически сохранённые,
но не проверенные AI references не считаются валидными входными данными генерации.

### Генерация 2D

AJAX flow:

1. Pricing разрешает актуальную цену `avatar_generation`;
2. проверяется эффективный набор face references: сохранённые + новые pending-файлы;
3. backend читает материализованный total balance пользователя `real + bonus`;
4. при недостаточном балансе внешний AI не вызывается;
5. если есть pending-файлы, они нормализуются в памяти и одним batch-вызовом
   `validateFaceReferences()` проверяются на ожидаемые ракурсы;
6. весь валидный batch атомарно сохраняется как AI-confirmed references;
7. если pending-файлов нет, уже сохранённые confirmed references переиспользуются без
   повторной проверки ракурса;
8. текущие параметры персонажа, формы, обуви и атрибутов нормализуются в предметный payload;
9. выбранная команда, если есть, повторно авторизуется по активному player membership;
10. выполняется provider call `generatePlayerCharacter()`;
11. синхронные providers сразу возвращают PNG, а `github_openai` создаёт
    `player_character_generations`, dispatch-ит workflow и возвращает HTTP 202;
12. frontend опрашивает private status endpoint до `completed|failed`, не очищая
    предыдущую 2D/3D-визуализацию;
13. полученный PNG хранится в private storage и отдаётся только владельцу.

Таким образом новые фото проходят проверку прямо внутри пользовательского действия
«Сгенерировать», но сохранённые ранее подтверждённые фото не создают повторную стоимость
валидации при каждой генерации.

Текущий flow делает balance preflight, но ещё не списывает деньги. Финансовое исполнение
должно использовать Purchase/hold или компенсирующий refund, чтобы внешний сбой не оставлял
пользователя без средств и результата.

## Машинные коды ошибок

Presentation возвращает стабильный `code`, а UI показывает локализованный текст.

- `insufficient_balance` — недостаточно средств;
- `pricing_unavailable` — цена услуги недоступна;
- `face_references_missing` — нет анфаса и/или профиля;
- `face_reference_invalid` — AI отклонил фото/ракурс;
- `ai_not_configured` — provider не подключён;
- `ai_connection_failed` — ошибка соединения;
- `ai_rate_limited` — provider ограничил частоту;
- `ai_request_rejected` — provider отклонил запрос;
- `generation_failed` — provider не вернул пригодный результат;
- `generation_timeout` — превышено время ожидания.

Provider-specific тексты/HTTP детали не должны становиться frontend-контрактом.

## Выбор provider

`PLAYER_CHARACTER_AI_PROVIDER` принимает `auto|github_openai|yandex|openai|null`.

В режиме `auto` приоритет имеет `github_openai`, если заданы GitHub token,
callback secret и repository. Затем идут Yandex AI Studio, прямой OpenAI и null provider.
На deploy `github_openai` становится default только когда оба GitHub-секрета доступны;
иначе Yandex остаётся fallback.

## OpenAI через GitHub Actions

`GitHubOpenAiPlayerCharacterAiGateway` реализует асинхронный production flow:

1. VDS создаёт generation job с `pending` и короткоживущий signed manifest URL;
2. VDS вызывает GitHub `workflow_dispatch`; на VDS нет OpenAI API request;
3. GitHub-hosted runner скачивает private references по signed URLs и вызывает
   `POST /v1/images/edits` с `gpt-image-2`, PNG и transparent background;
4. runner отправляет `processing|completed|failed` callback в MSKBA;
5. MSKBA проверяет timestamp, SHA-256 файла/метаданных и HMAC, валидирует
   PNG/прозрачные углы и сохраняет файл в private storage.

Личные фото не коммитятся в GitHub и не попадают в artifacts. Signed URLs и generation job
имеют TTL. Callback идемпотентен: terminal state защищён row lock, поэтому повторный
delivery не создаёт второй результат, а конкурентные terminal callbacks сериализуются.
Незавершённый job переходит в `generation_timeout` после TTL.

Face preflight в этом provider локально проверяет, что файл читается как изображение
и не слишком мал. Ракурс и identity fidelity оцениваются в самой генерации, без
отдельного платного AI-validation call.

Production secrets в GitHub Actions: `OPENAI_API_KEY`, `MSKBA_GITHUB_AI_TOKEN` и
`MSKBA_GITHUB_AI_CALLBACK_SECRET`. Первый доступен только runner. Deploy передаёт на VDS только
токен запуска workflow и callback secret как `GITHUB_AI_*` env. GitHub token должен иметь минимальное repository
permission `Actions: write`.

## Yandex AI Studio provider

`YandexPlayerCharacterAiGateway` использует OpenAI-compatible Responses API по адресу
`https://ai.api.cloud.yandex.net/v1`.

Проверка pending face references выполняется мультимодальной Qwen через image inputs и
strict JSON schema. Внутренне сохранённые face references остаются WebP, но перед отправкой
в Yandex Responses gateway перекодирует их в PNG data URL: production показал, что synthetic
PNG успешно проходит multimodal structured smoke, тогда как реальные WebP-запросы завершались
provider-level `status=failed` при HTTP 200.

Production-проверка генерации показала рабочий Responses contract: image tool вызывается без
явных `model` и `output_format`, с `action=generate`, `input_fidelity=high`, quality/size.
Gateway принимает первый completed `image_generation_call`; последующие tool calls игнорируются,
поскольку Yandex фактически может вернуть их несколько даже при `max_tool_calls=1`.

Responses API на production может ответить HTTP 200 с `status=queued|in_progress` и пустым
`output`, пока image tool ещё работает. Такой ответ не считается ошибкой: gateway использует
полученный response `id` и опрашивает `GET /v1/responses/{id}` до terminal state в пределах
исходного timeout. Только `completed` передаётся в parser; `failed|cancelled|incomplete`
маппятся в стабильную ошибку MSKBA.

Прозрачный background текущим Yandex image tool не гарантируется, поэтому Yandex-only pipeline
просит ровный зелёный фон `#00FF00` и после успешной генерации локально удаляет фон. Cleanup
строит chroma mask и flood-fill от краёв изображения: прозрачными становятся только зелёные
области, соединённые с рамкой, поэтому изолированные зелёные детали игрока не удаляются.
Успешный cleanup нормализует результат в transparent PNG. Обработка fail-open: при ошибке,
неожиданном фоне или отключённом `YANDEX_AI_REMOVE_GREEN_BACKGROUND` сохраняется исходный
валидный provider result. OpenAI pipeline этой обработкой не затрагивается.

Production secrets: `YANDEX_AI_API_KEY` и `YANDEX_AI_FOLDER_ID`. Deploy синхронизирует
их из GitHub Actions secrets. Yandex остаётся fallback/rollback после включения
`github_openai` по умолчанию.

## OpenAI provider

OpenAI остаётся fallback/provider override. При `PLAYER_CHARACTER_AI_PROVIDER=openai`
и настроенном `OPENAI_API_KEY` контейнер использует `OpenAiPlayerCharacterAiGateway`.

Face validation uses the Responses API with image inputs and strict structured output.
Pending face references are sent in one validation request. The provider does not identify
the person; it only checks image usability and expected front/left/right orientation.

Player generation uses the Images Edit API so all confirmed face references can be passed
as identity inputs. The provider requests `background=transparent` and PNG output, then
MSKBA independently rejects responses whose corner pixels are not transparent.

Provider model names, timeouts, image size and quality are environment-configurable via
`config/services.php`. Secrets must live only in server environment configuration.
