# AI services

## Назначение

`App\Modules\Ai` — внутренний integration boundary для внешних AI-провайдеров.
Предметные контексты не должны вызывать OpenAI или другой provider напрямую из
контроллера, Blade или frontend. Identity передаёт нормализованный предметный payload
через `PlayerCharacterAiGateway`.

На Task 225 зарегистрирован `NullPlayerCharacterAiGateway`. Он намеренно возвращает
стабильную ошибку `ai_not_configured`: реальный provider и секреты ещё не подключены.

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
10. выполняется отдельный provider call `generatePlayerCharacter()`;
11. во время запроса предыдущая 2D/3D-визуализация остаётся на сцене;
12. ошибка AI не очищает существующую модель.

Таким образом новые фото проходят проверку прямо внутри пользовательского действия
«Сгенерировать», но сохранённые ранее подтверждённые фото не создают повторную стоимость
валидации при каждой генерации.

Task 225 выполняет preflight и provider boundary, но не списывает деньги: реальный AI
provider ещё не подключён. При активации provider финансовое исполнение должно использовать
Purchase/hold или компенсирующий refund, чтобы внешний сбой не оставлял пользователя без
средств и результата.

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

`PLAYER_CHARACTER_AI_PROVIDER` принимает `auto|yandex|openai|null`.

В режиме `auto` приоритет имеет Yandex AI Studio, если одновременно заданы
`YANDEX_AI_API_KEY` и `YANDEX_AI_FOLDER_ID`. Если Yandex не настроен, используется
OpenAI при наличии `OPENAI_API_KEY`. Иначе остаётся null provider.

## Yandex AI Studio provider

`YandexPlayerCharacterAiGateway` использует OpenAI-compatible Responses API по адресу
`https://ai.api.cloud.yandex.net/v1`.

Проверка pending face references выполняется мультимодальной Qwen через image inputs и
strict JSON schema. Генерация персонажа выполняется через Responses API с инструментом
`image_generation` (Alice AI ART), которому передаются те же face references и
`input_fidelity=high` для максимального сохранения черт лица.

На текущем API Yandex параметр прозрачного background помечен как не поддерживаемый.
MSKBA поэтому не считает opaque PNG корректным финальным результатом: gateway проверяет
alpha самостоятельно и возвращает `generation_background_not_transparent`, если
провайдер не дал реально прозрачный фон. Это capability gap, который нужно закрыть
отдельным background-removal этапом, если реальные генерации Yandex стабильно opaque.

Production secrets: `YANDEX_AI_API_KEY` и `YANDEX_AI_FOLDER_ID`. Deploy синхронизирует
их из GitHub Actions secrets и переключает player-character provider на Yandex только
когда присутствуют оба значения.

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
