# Identity

Модуль ограниченного контекста для пользователей, аутентификации, профиля и ролей.

## Ответственность

- Регистрация, вход и выход пользователя.
- Создание аккаунта через `CreateUserAccountHandler`.
- Базовый профиль пользователя в таблице `profiles`.
- Системные роли и роли участия.
- Browser fingerprints для гостевых и security-сценариев.
- Actor attribution: централизованный субъект действия для гостя, пользователя и системы.
- Предметные профили участия пользователя, например `PlayerProfile`.
- Проверки пользователя перед отображением страниц личного кабинета.

## Структура

- `Domain`: бизнес-модели, enum, события, исключения, value object.
- `Application`: use case, DTO и application-сервисы.
- `Infrastructure`: ACL, интеграции, service provider, factory.
- `Presentation`: HTTP-контроллеры, request-классы, resource-классы.
- `Tests`: feature- и unit-тесты модуля.

## Actor attribution

Новые сущности проекта не должны хранить автора действия через отдельные пары `created_by_user_id` + `created_by_fingerprint_id`.

Канонический подход:

- получить текущий actor через `CurrentActorResolver`;
- передать `?Actor` в use case;
- сохранить `created_by_actor_id` в доменной сущности.

Подробнее: `docs/architecture/actors.md`.
