# Универсальные реакции: feed + Telegram

## Цель

Добавить единый доменный механизм пользовательских реакций, который сначала используется для материалов `/feed`, а затем без изменения схемы БД может быть подключён к площадкам, игрокам и другим сущностям.

## Доменная модель

`reactions` хранит текущее состояние одного actor для одной сущности:

- `subject_type` + `subject_id` — сущность (`content`, далее `venue`, `player`);
- `actor_type` + `actor_id` — логическая личность (`user` либо ещё не связанный `telegram` user);
- `user_id` — nullable FK для локального пользователя; при hard-delete пользователя его реакции каскадно удаляются;
- `value`: `1` like, `-1` dislike, `0` neutral/tombstone;
- `source`: `web` или `telegram`;
- `source_occurred_at` + `source_sequence` защищают от запоздавших/out-of-order внешних updates;
- `source_metadata` содержит только технический контекст источника.

На `(subject_type, subject_id, actor_type, actor_id)` действует unique constraint. Повторный клик не создаёт историю кликов, а изменяет текущее состояние.

`0` хранится как tombstone вместо физического удаления реакции, чтобы запоздавший Telegram update не мог восстановить более старое состояние.

Записи одного actor для одного subject сериализуются общим application-level cache lock и затем изменяются внутри DB transaction. Это защищает не только обновление существующей строки, но и first-write race между web и Telegram, когда строки ещё нет.

## Web

Для опубликованного материала доступны 👍 / 👎:

- первый клик устанавливает реакцию;
- повторный клик по активной реакции снимает её;
- клик по противоположной реакции переключает голос;
- голосовать может только авторизованный пользователь;
- счётчики видны всем;
- агрегаты на странице feed загружаются пачкой, без N+1.

HTTP endpoint универсальный: `PUT /reactions/{subjectType}/{subjectId}`. На первом этапе `ReactionSubjectGuard` разрешает запись только для опубликованного `content`; adapters для `venue`/`player` добавляются отдельно.

## Telegram

Для публикаций контента уже существует связь `content_item_id -> telegram chat -> message_id`. Входящий `message_reaction` находится по `chat.id + message_id` и преобразуется в реакцию того же `ContentItem`.

Webhook и polling явно подписываются на `message_reaction`. Бот должен быть администратором чата, иначе Telegram не присылает персональные reaction updates. Production deploy уже выполняет `telegram:configure-updates --if-configured`, поэтому после будущего merge webhook будет автоматически зарегистрирован с новым `allowed_updates`.

Классификация v1:

- положительные emoji настраиваются в `config/telegram.php` и дают `+1`;
- отрицательные дают `-1`;
- нейтральные/custom/paid reactions не учитываются;
- если одновременно присутствуют поддерживаемые реакции обеих полярностей, состояние считается нейтральным;
- удаление/замена Telegram reaction обновляет то же состояние, а не добавляет новую запись;
- reactions от bot actor не учитываются.

Если `telegram_user_id` уже связан с `TelegramAccount`, Telegram и web используют actor `user:{user_id}` и физически голосуют одной записью. Если связи ещё нет, используется `telegram:{telegram_user_id}`; при следующем взаимодействии после связывания старый внешний actor для этого subject схлопывается в локального пользователя.

Anonymous aggregate `message_reaction_count` в v1 не используется, потому что он не содержит идентичность голосующего и не позволяет корректно дедуплицировать web/Telegram.

## Интеграция с task 101

Web и Telegram всегда разрешают связанного пользователя в canonical identity. Если `TelegramAccount` физически остался на alias, он всё равно использует actor канонического пользователя. При объединении identity существующие user/Telegram reactions схлопываются по каждой сущности: сохраняется последнее актуальное действие, поэтому один человек не учитывается несколько раз.

## Текущая проверка

На текущем `main` до merge task 101:

- focused regression: 32 tests passed, 148 assertions;
- route cache проходит;
- frontend build проходит;
- full suite diagnostic: 488 passed, 5 известных legacy failures, не связанных с Reaction (3 Telegram MiniApp assertions, Tournament catalog, Venue raw-address).

## Проверка перед merge

- migrations на SQLite test environment и PostgreSQL-compatible schema;
- web like/dislike/switch/remove;
- guest/validation/unpublished guards;
- feed counters + viewer state;
- Telegram positive/negative/neutral mapping;
- linked Telegram account использует тот же user actor;
- stale Telegram update не перезаписывает более новый web vote;
- webhook и polling принимают `message_reaction`;
- frontend build;
- canonical aliases и Telegram accounts на aliases учитываются как один actor.
