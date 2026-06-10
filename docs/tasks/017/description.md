# 017 - Рефакторинг базового production-safe сидера

## Оригинальное описание

Пользователь попросил отрефакторить базовый сидер, который будет использоваться и в production. В сидере должны оставаться только:

- identity bootstrap: `user + profile` для `superadmin`;
- пароль superadmin: `F[etyyj!`;
- справочники `metro_lines` и `metro_stations`;
- предварительно подготовленный JSON с метро, например из публичных источников вроде HeadHunter API.

## Подробное описание

Текущий `DatabaseSeeder` является demo/dev сидером: он создает несколько системных пользователей, fake users, роли участия, player profiles, площадки и контракты. Для production-base это слишком много и небезопасно.

Нужно заменить его на минимальный идемпотентный bootstrap:

- создать или обновить `superadmin` со статусом `confirmed` и системной ролью `superadmin`;
- создать или обновить связанный профиль пользователя;
- при первом создании задать пароль `F[etyyj!`;
- не сбрасывать пароль существующего `superadmin` при повторном запуске сидера;
- наполнить `metro_lines` и `metro_stations` из локального JSON;
- не создавать demo users, venues, contracts и случайные fake-данные.

Данные метро:

- источник: `nalgeon/metro`, файл `data/metro.ru.json`;
- upstream источника указан как HeadHunter API;
- в репозиторий добавляется нормализованный Moscow-only JSON `database/seeders/data/moscow_metro.json`;
- сидер не ходит в сеть при запуске.

## Проверки

- `php -l database/seeders/DatabaseSeeder.php`;
- `php artisan migrate:fresh --seed --env=testing`;
- targeted DB assertions через artisan/tinker или test;
- `git diff --check`.

## Результат

`DatabaseSeeder` заменен на production-safe bootstrap сидер:

- удалено создание demo users, admin/moderator/editor, fake users, participation roles, player profiles, venues и contracts;
- добавлено создание/обновление `superadmin`;
- при первом создании `superadmin` задается пароль `F[etyyj!`;
- при повторном запуске сидер не сбрасывает пароль существующего `superadmin`;
- профиль `superadmin` создается или обновляется;
- добавлено наполнение `metro_lines` и `metro_stations`;
- данные метро хранятся локально в `database/seeders/data/moscow_metro.json`;
- JSON подготовлен из публичного набора `nalgeon/metro`, который указывает HeadHunter API как upstream source.

Проверки выполнены:

- `php -l database/seeders/DatabaseSeeder.php` - пройдено;
- `php -l tests/Feature/Database/DatabaseSeederTest.php` - пройдено;
- `php artisan test tests/Feature/Database/DatabaseSeederTest.php` - пройдено, 2 tests / 14 assertions;
- `php artisan migrate:fresh --seed --env=testing` - пройдено;
- `git diff --check` - пройдено.
