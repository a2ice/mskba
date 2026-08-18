# Универсальные реакции: feed + Telegram

## Цель

Добавить единый доменный механизм пользовательских реакций, который сначала используется для материалов `/feed`, а затем без изменения схемы БД может быть подключён к площадкам, игрокам и другим сущностям.

## Доменная модель

`reactions` хранит текущее состояние одного actor для одной сущности:

- `subject_type` + `subject_id` — сущность (`content`, далее `venue`, `player`);
- `actor_type` + `actor_id` — логическая личность (`user` либо ещё не связанный `telegram` user);
- `user_id` — nullable FK для локального пользователя;
- `value`: `1` like, `-1` dislike, `0` neutral/tombstone;
- `source`: `web` или `telegram`;
- `source_occurred_at` + `source_sequence` защищают от запоздавших/out-of-order внешних updates;
- `source_metadata` содержит только технический контекст источника.

На `(subject_type, subject_id, actor_type, actor_id)` действует unique constraint. Повторный клик не создаёт историю кликов, а изменяет текущее состояние.

`0` хранится как tombstone вместо физического удаления реакции, чтобы запоздавший Telegram update не мог восстановить более старое состояние.

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

Webhook и polling должны явно подписываться на `message_reaction`. Бот должен быть администратором чата, иначе Telegram не присылает персональные reaction updates.

Классификация v1:

- положительные emoji настраиваются в `config/telegram.php` и дают `+1`;
- отрицательные дают `-1`;
- нейтральные/custom/paid reactions не учитываются;
- если одновременно присутствуют поддерживаемые реакции обеих полярностей, состояние считается нейтральным;
- удаление/замена Telegram reaction обновляет то же состояние, а не добавляет новую запись.

Если `telegram_user_id` уже связан с `TelegramAccount`, Telegram и web используют actor `user:{user_id}` и физически голосуют одной записью. Если связи ещё нет, используется `telegram:{telegram_user_id}`; при следующем взаимодействии после связывания старый внешний actor для этого subject схлопывается в локального пользователя.

Anonymous aggregate `message_reaction_count` в v1 не используется, потому что он не содержит идентичность голосующего и не позволяет корректно дедуплицировать web/Telegram.

## Интеграция с task 101

Ветка создана от `main` до завершения task 101. До merge reactions в `main` необходимо подтянуть итоговый `main` после 101 и адаптировать `ReactionActorResolver` к canonical user identity. Все остальные слои должны остаться независимыми от деталей canonicalization.

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
- после merge task 101 — повторный regression/full suite и отдельная проверка canonical aliases.
