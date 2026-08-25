# 007 — Команды, идемпотентность, timeline и события

## Цель
Сделать изменения аренды повторяемыми, аудируемыми и безопасными для внешних интеграций.

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

