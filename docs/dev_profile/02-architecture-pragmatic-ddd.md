# 02. Слои и pragmatic DDD

[← Оглавление](README.md) · [Далее →](03-php-modern-features.md)

## Главная мысль

**DDD** — предметные границы, язык бизнеса и инварианты. **Pragmatic DDD** — применять их там, где сложность реальна, не строить десятки абстракций для простого CRUD. В MSKBA — **модульный монолит**, а не микросервисы и не «чистая архитектура» в догматическом смысле.

## Упрощённый путь команды

~~~text
HTTP / контроллер (Presentation)
       ↓ валидирует ввод, определяет actor
UseCase / Handler (Application)
       ↓ координирует транзакцию и зависимости
Domain (Model, Enum, VO, rules, lifecycle)
       ↕
Infrastructure (ORM, integrations, listener, provider)
       ↓
PostgreSQL / Redis / внешние сервисы

После коммита → event/listener/job → уведомление / Telegram / Reverb
~~~

| Слой | Зачем | Пример |
| --- | --- | --- |
| Domain | Что разрешено предметной областью; сущности и значения | [VenueBookingLifecycle](../../app/Modules/VenueBooking/Domain/Services/VenueBookingLifecycle.php), [UserSystemRoleEnum](../../app/Modules/Identity/Domain/Enums/UserSystemRoleEnum.php) |
| Application | Как выполнить сценарий, границы транзакции | [RequestVenueBookingHandler](../../app/Modules/VenueBooking/Application/UseCases/RequestVenueBookingHandler.php) |
| Infrastructure | Как подключить внешнее и фреймворк | [VenueBookingOutboxDispatcher](../../app/Modules/VenueBooking/Application/Services/VenueBookingOutboxDispatcher.php), [NotificationServiceProvider](../../app/Modules/Notification/Infrastructure/Providers/NotificationServiceProvider.php) |
| Presentation | HTTP, Blade/JS, визуализация | [VenueController](../../app/Modules/Venue/Presentation/Http/Controllers/VenueController.php) |

**Важно:** локальная структура не абсолютно строгая. Eloquent-модели живут в Domain и зависят от Laravel, а OutboxDispatcher — Application service, хотя обращается к БД и событиям. Это осознанный пример прагматичной, **не hexagonal «по учебнику»** архитектуры.

## Bounded contexts: карта модулей

- **Identity / Contact:** аккаунт, права, профили, подтверждение контактов.
- **Venue / Contract:** площадка, владельцы, сотрудники, локальные разрешения.
- **VenueBooking / Coordination:** бронь, слот, состояние, согласование участников.
- **Event / Team / Tournament / SportsSection:** игра, состав команды, турнир, тренировка.
- **Finance / Pricing / Rewards / Acquisition:** кошелёк, тарифы, начисления, приглашения.
- **Notification / Telegram / Vk:** доставка сообщений и интеграции.
- **Moderation / Content / Audit / Media / Ai:** проверка заявок, контент, аудит, медиа и генерация.

Модули **не являются отдельными сервисами с независимыми БД**. Межмодульные вызовы допустимы, и это компромисс в пользу скорости разработки.

## Пример командного сценария

[RequestVenueBookingHandler](../../app/Modules/VenueBooking/Application/UseCases/RequestVenueBookingHandler.php) принимает Actor, публичный ID расчёта и idempotency key. Проверяет статус аккаунта и ограничения площадки, блокирует нужные строки, валидирует актуальность quote, создаёт бронь, участника и запись перехода; затем фиксирует Outbox-событие. Контроллеру не нужно знать все эти правила.

**Инвариант:** одна и та же quotation не должна создавать несколько независимых бронирований или резервировать недоступный слот.

## Какие паттерны здесь различать

- **Use Case / Application Service:** сценарий бизнеса, например запрос аренды.
- **Dependency Injection:** зависимости через конструктор, которые собирает Laravel container.
- **Domain Service:** правила переходов VenueBookingLifecycle, не привязанные к UI.
- **DTO:** перенос проверенной структуры между границами; например [ProfileDTO](../../app/Modules/Identity/Application/DTO/ProfileDTO.php).
- **Repository:** Eloquent query builder часто используется напрямую. **Не заявляй**, что для всех агрегатов есть собственный Repository interface.
- **Strategy/Registry:** сменяемые механизмы начисления и подтверждения; см. [главу 04](04-events-and-integration.md).
- **State machine:** ограниченные переходы броней и заявок.
- **Service Provider:** регистрация обработчиков и зависимостей.
- **Observer:** точечная реакция на lifecycle модели, но для кросс-модульных фактов предпочтительнее явные события.

## SOLID без лозунгов

SRP: расчёт статуса брони отделён от UI и доставки Telegram. OCP: механизм бонуса можно добавить через интерфейс без переписывания ядра. DIP: подтверждение контакта зависит от контракта Strategy, не от SMTP-класса. **Trade-off:** лишние интерфейсы ради SOLID усложняют простой CRUD.

## Частые вопросы

**«Разница DTO и VO?»** DTO — контейнер передачи данных, допустима простая структура. VO — объект со смыслом и правилами, например нормализованный адрес контакта, сравниваемый по значению.

**«Разница Handler и Domain Service?»** Handler управляет конкретным действием, зависимостями и транзакцией. Domain Service выражает правило предметной области; может переиспользоваться разными сценариями.

**«Почему modular monolith?»** Единая транзакционная БД и единый deploy, но модули ограничивают ответственность. Отдельные сервисы нужны при реальной необходимости независимого масштабирования и релизов.
