# 007 — Команды, идемпотентность, timeline и события

## Цель
Сделать изменения аренды повторяемыми, аудируемыми и безопасными для внешних интеграций.

## Статус
Выполнена в `feature/115`.

## Доменные изменения
Timeline фиксирует бизнес-факты; conversation хранится отдельно. Команда имеет actor, correlation и idempotency key.

## Миграции
Command receipts/idempotency, booking timeline и при необходимости transactional outbox с уникальными ключами.

## Handlers и сервисы
Application handlers открывают транзакцию, блокируют агрегат, проверяют policy, записывают receipt и публикуют after commit.

## Права
Авторизация проверяется внутри handler, даже если HTTP gate уже выполнил проверку.

## UI/API
Mutating API принимает idempotency key; одинаковый key+payload возвращает прежний результат, иной payload — conflict.

## События и jobs
Outbox dispatcher с retry/backoff; consumers дедуплицируют сообщения.

## Тесты
Повтор HTTP/Telegram callback, rollback, crash между commit и dispatch, смена прав до выполнения job.

## Обратная совместимость
Старые endpoints могут вызывать те же handlers через адаптер.

## Критерии приёмки
- повтор команды не создаёт второй эффект;
- audit и domain event соответствуют одной транзакции;
- listeners не выполняют критические изменения до commit.

## Результат выполнения

- все booking handlers выполняются через `IdempotentVenueBookingCommand`;
  command receipt хранит actor, command, UUID idempotency/correlation IDs,
  канонический payload hash и сохранённый результат в той же транзакции;
- одинаковый key+payload возвращает прежнюю бронь без нового перехода, outbox
  message или побочного эффекта; тот же actor+key с другой командой/payload
  получает `409 IDEMPOTENCY_KEY_REUSED`;
- mutating HTTP API обязательно принимает `Idempotency-Key` либо hidden UUID;
  web, JSON и будущий Telegram adapter используют одни application handlers;
- timeline связывается с command receipt и correlation ID;
- прямые after-commit events заменены transactional outbox. Message атомарен с
  aggregate/timeline, job имеет retry/backoff, scheduler подбирает pending и
  восстанавливает stale processing claim после crash;
- outbox публикует только whitelist доменных событий с уникальным message ID.
  `VenueBookingEventConsumer::once` даёт listeners атомарную дедупликацию:
  failed effect откатывает consumer receipt и может быть повторён;
- повторная авторизация остаётся внутри handler callback. Новый command key
  после изменения прав выполняет актуальную проверку, а replay уже совершённой
  команды только возвращает зафиксированный результат;
- тесты покрывают HTTP replay, payload conflict, один timeline/outbox effect,
  rollback, сбой listener, backoff, восстановление после crash и consumer dedupe.

Outbox реализует at-least-once доставку: crash после вызова listener, но до
`published`, может привести к повторной доставке. Поэтому критический consumer
обязан использовать message ID через `VenueBookingEventConsumer`; полагаться на
«ровно один вызов транспорта» нельзя.
