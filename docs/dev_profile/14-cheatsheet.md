# 14. Шпаргалка за 5 минут

[← Оглавление](README.md) · [Далее →](15-source-audit.md)

## Скажи про MSKBA за 20 секунд

«Разрабатываем баскетбольную платформу на PHP 8.3+/Laravel 13, PostgreSQL 17 и Redis 7. Модульный монолит с pragmatic DDD: бизнес-сценарии в use case, инварианты в Domain, интеграции через events/listeners/jobs, уведомления через Reverb. Frontend — Blade + JS/Vue 3, отдельный Inertia preview. GitHub Actions проверяет тесты и доставляет сборки через dev/prod workflows».

## Термин → одна мысль → пример

| Тема | Одно предложение | Проект |
| --- | --- | --- |
| DDD | Границы по бизнес-смыслу | Identity, VenueBooking, Finance |
| Bounded Context | Своя модель и язык предметной области | Venue ≠ VenueBooking |
| Modular monolith | Одно приложение, разные модули | app/Modules |
| Use Case | Завершённый бизнес-сценарий | RequestVenueBookingHandler |
| Value Object | Нормализованное значение с инвариантом | UsernameVO, ContactValue |
| enum | Типизированные фиксированные состояния | UserSystemRoleEnum |
| Attribute | Метаданные PHP | #[Fillable] |
| Strategy | Разные реализации одного контракта | ContactVerificationStrategy |
| Event | Факт после изменения | UserContactConfirmed |
| Listener | Реакция на факт | GrantOperationalPermissions... |
| Job | Единица фоновой работы | DispatchVenueBookingOutboxJob |
| Atomicity | Или всё, или rollback | DB::transaction |
| Idempotency | Повтор даёт прежний результат | receipt + key + hash |
| Pessimistic lock | Не позволить параллельно изменить строку | lockForUpdate |
| Optimistic lock | Проверить ожидаемую версию | optimistic_version |
| Outbox | Запомнить обязанность опубликовать событие | VenueBookingOutbox |
| Normalization | Не дублировать связанные факты | bookings/parties/users |
| Denormalization | Сохранить нужный snapshot для чтения/истории | quote_snapshot |
| EXISTS | Проверить наличие без лишних строк | VenueMembershipAccess |
| N+1 | Запрос внутри цикла по связям | решается with()/withCount() |
| Cache-aside | MISS → запрос в БД → сохранить TTL | VenueSearchCache |
| Cache invalidation | Сделать старые ключи неактуальными | venue-search:version |
| RBAC | Политика по роли | UserSystemRoleEnum |
| ACL | Доступ к действию и ресурсу | договорные права на Venue |
| WebSocket | Push по постоянному соединению | Reverb |
| Echo | Клиент подписки | resources/js/realtime.js |
| Reconciliation | После push сверить с БД | notification-counts.js |
| CI | Тестировать каждый релевантный change | .github/workflows/ci.yml |
| CD | Выпускать проверенный код в среду | ci.yml/dev, deploy.yml/prod |
| Docker Compose | Связать контейнеры в среду | compose.yaml |

## Три фразы, которые производят хорошее впечатление

1. «Сначала определяем **инвариант**, затем границу транзакции и последствия повторного вызова».
2. «EXISTS / JOIN / индекс выбираем по **реальному SQL-плану и кардинальности**, а не по вкусу».
3. «Reverb отвечает за **оперативность**, а PostgreSQL + reconciliation — за **корректность**».

## Три опасных утверждения

- «Всё работает на Vue SPA»: **нет**, UI гибридный.
- «Доставка exactly-once»: **нет таких подтверждённых гарантий**, используем идемпотентность.
- «Обновление профиля с тредами работает на prod»: на момент аудита код **WIP в локальном dev**.

**Если интервьюер копает глубоко:** попроси выбрать один сценарий, затем разбери его: [бронирование](05-atomicity-idempotency.md), [ACL](08-access-permissions.md), [события](04-events-and-integration.md).

[Полные вопросы](13-interview-qa.md) · [Оглавление](README.md)
