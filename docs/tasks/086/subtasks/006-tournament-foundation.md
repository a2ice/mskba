# 006 - Tournament foundation

## Цель

Добавить самостоятельный агрегат Tournament с CRUD, статусами, media и публичной страницей.

## Статус

Выполнено.

## Работы

- Tournament model, enum, factory и migration;
- создание только подтверждённым пользователем;
- ID+alias routing без unique alias;
- short/full descriptions и обложка через Media;
- confirmed/unconfirmed/cancelled;
- soft delete и аудит;
- каталог и базовые public/manage views.

## Приёмка

- одинаковые title/alias допустимы;
- URL однозначен по ID;
- lifecycle и soft delete защищены application-сервисом;
- заглушка каталога заменена реальными данными.

## Результат

- добавлены Tournament model, status enum, factory, migration, audit и soft delete;
- alias не уникален, идентичность публичного URL определяется `id-alias`;
- PostgreSQL защищает период CHECK-constraint, application handlers повторно проверяют инвариант;
- создание доступно только подтверждённому пользователю и даёт status `confirmed`;
- владелец может менять паспорт, статус, комментарий и выполнять soft delete;
- отменённый Tournament нельзя реактивировать или редактировать;
- добавлены Media morph map, nullable cover и безопасная замена WebP-обложки;
- статическая заглушка заменена каталогом с периодами, поиском и реальными карточками;
- добавлены public, create и management surfaces;
- ownership проверяется по устойчивому user identity, а Actor сохраняет происхождение действия;
- локальная PostgreSQL-миграция применена;
- Tournament/Event/Database профиль: 56 тестов, 545 assertions; frontend build успешен.
