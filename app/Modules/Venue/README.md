# Venue

Модуль ограниченного контекста для баскетбольных площадок и доступа к ним.

## Ответственность

- Публичный список площадок и просмотр площадки.
- Список площадок в личном кабинете.
- Создание площадки подтвержденным пользователем с ролью участия `venue_related`.
- Проверка доступа к площадке по membership-contract permissions.
- Bootstrap-доступ создателя только для площадок без действующего owner membership contract.
- `created_by_user_id` остается audit/source field.

## Структура

- `Domain`: бизнес-модели, enum, события, исключения, value object.
- `Application`: use case, DTO, application-сервисы и builders.
- `Infrastructure`: ACL, интеграции, service provider, factory.
- `Presentation`: HTTP-контроллеры, request-классы, resource-классы.
- `Tests`: feature- и unit-тесты модуля.
