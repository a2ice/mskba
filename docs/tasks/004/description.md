# 004 - Добавить сущность `UserProfile` в контексте `Identity` со связью `User` 1-to-1 и автосозданием при регистрации

## Оригинальное описание

Нужно сделать новую сущность `UserProfile`.

Требования:

- `User` -> `UserProfile` как связь `1-to-1`;
- базовый набор nullable-полей:
  - `first_name`
  - `last_name`
  - `middle_name`
  - `birth_date`
  - `gender`
- `gender` как enum со значениями:
  - `male`
  - `female`
- сущность предполагается делать внутри контекста `Identity`;
- при регистрации нового пользователя сразу должна создаваться связанная запись `UserProfile` с пустыми полями.

## Подробное описание

На этапе планирования дополнительно зафиксировано:

- `UserProfile` создается внутри контекста `Identity`;
- связь должна быть `User` -> `hasOne(UserProfile)` и `UserProfile` -> `belongsTo(User)`;
- для пола нужен отдельный enum `UserGenderEnum` со значениями `male` и `female`;
- поля профиля на первой итерации остаются nullable:
  - `first_name`
  - `last_name`
  - `middle_name`
  - `birth_date`
  - `gender`
- автосоздание профиля нужно делать прямо в `RegisterContactFirstHandler` в том же transactional flow, где сейчас создаются `User` и `Contact`;
- event-driven подход для этого кейса пока не вводится, потому что текущий локальный паттерн `Identity` — явная orchestration-логика внутри use case;
- нужно учесть, что `UserFactory` сейчас автоматически создает `Contact`, но не создает профиль, поэтому factory и тесты тоже нужно привести к новой модели.

План реализации:

1. Добавить enum `UserGenderEnum`.
2. Добавить модель `UserProfile` в `Identity`.
3. Добавить таблицу `user_profiles` со связью `1-to-1` к `users`.
4. Добавить relation methods в `User` и `UserProfile`.
5. Создавать `UserProfile` в `RegisterContactFirstHandler` сразу после `User::create(...)`.
6. Обновить `UserFactory`, чтобы после создания пользователя появлялся и связанный профиль.
7. Обновить feature-тест регистрации и при необходимости добавить отдельный тест на связь `User` <-> `UserProfile`.
8. Обновить техническую и продуктовую документацию по модели пользователя.

Проверки:

- `php artisan test tests/Feature/AuthClassicFlowTest.php`
- при необходимости отдельный test для `UserProfile`

## Результат выполнения

Реализована новая сущность `UserProfile` внутри `Identity`:

- добавлен enum `UserGenderEnum` со значениями `male` и `female`;
- добавлена модель `UserProfile`;
- добавлена таблица `user_profiles` со связью `1-to-1` к `users`;
- в `User` добавлена связь `profile()`;
- профиль создается прямо в `RegisterContactFirstHandler` сразу после `User::create(...)` в той же транзакции;
- `UserFactory` теперь тоже создает связанный пустой профиль автоматически.

Документация обновлена:

- техническая модель профиля описана в `docs/specification/identity-user-profile.md`;
- продуктовая документация регистрации и личного кабинета обновлена с учетом базового профиля пользователя.

## Проверка результата

Проверки:

- `php artisan test tests/Feature/AuthClassicFlowTest.php`

Ожидаемо дополнительно проверяется:

- регистрация создает `UserProfile` с пустыми nullable-полями;
- `UserFactory` создает связанный профиль автоматически.
