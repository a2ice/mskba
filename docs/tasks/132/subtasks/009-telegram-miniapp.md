# 009 — Telegram и Mini App

## Цель
Поддержать координацию и ключевые действия аренды из Telegram без отдельной бизнес-логики.

## Статус
Выполнена в `feature/115`.

## Доменные изменения
Нет: Telegram — адаптер тех же application commands и read models.

## Миграции
Таблица привязок сообщений/чатов к coordination/booking и уникальные update/callback IDs.

## Handlers и сервисы
Deep links, callbacks Join/Leave/Open; подпись Mini App проверяется, затем вызывается общий handler.

## Права
Telegram identity должна быть подтверждена и принадлежать actor; права повторно проверяются сервером.

## UI/API
Карточка статуса и кнопки; устаревший callback показывает актуальное состояние вместо повторного эффекта.

## События и jobs
Асинхронное обновление сообщений после commit, retry с дедупликацией и rate limit.

## Тесты
Неверная подпись, replay callback, удалённое сообщение, закрытая coordination, отозванное право.

## Обратная совместимость
Текущая Telegram-авторизация и уведомления не меняются.

## Критерии приёмки
- web и Telegram дают одинаковый бизнес-результат;
- повтор update безопасен;
- сбой Telegram не откатывает booking.

## Результат выполнения

- публичный `VenueRentalCoordination` после commit получает отдельные привязки
  chat/message в активных Telegram-чатах, уже разрешённых для coordination;
  приватные сборы не публикуются;
- Telegram-карточка показывает площадку, интервал, число заинтересованных,
  актуальный статус booking и обязательное предупреждение, пока слот не
  удерживается; кнопка Open ведёт в Main Mini App по allowlisted deep link;
- inline Join/Leave callback проверяет сохранённую пару chat/message и берёт
  Telegram ID только из подписанного Bot API update. Identity резолвится через
  общий Telegram account, после чего вызываются те же Join/Leave handlers, что
  использует web;
- вступление требует подтверждённого канонического пользователя; закрытый сбор,
  отозванная доступность и replay возвращают актуальное состояние вместо
  повторного эффекта;
- `telegram_venue_rental_updates` хранит уникальные callback/update IDs,
  действие, попытки и результат. Cache lock сериализует одновременную доставку,
  а доменная идемпотентность делает безопасным retry после сбоя между командой
  и записью receipt;
- webhook и long polling передают `update_id` в общий queued adapter; прежние
  event/coordination/auth callback остаются без изменений;
- Created/Joined/Closed/Converted и все booking lifecycle events асинхронно
  синхронизируют карточку. Job имеет retry/backoff и cache rate-limit lock;
  удалённое сообщение создаётся заново, `message is not modified` считается
  успешной синхронизацией;
- Mini App продолжает использовать существующую HMAC-проверку `initData` и
  Laravel-сессию. Resolver принимает только
  `rental_coordination_{public_uuid}`, проверяет feature flag и доступ к
  приватному сбору, затем возвращает относительный внутренний URL.

Тесты покрывают публикацию и карточку, callback replay, сохранение update ID,
закрытый сбор, неподтверждённую identity, удалённое сообщение, приватный deep
link и неверную подпись Mini App. Отдельно пройдена регрессия прежних Telegram
Event/Coordination/Auth flows.

Исходящий Telegram API не вызывается внутри транзакций Coordination или
VenueBooking. Поэтому медленный или недоступный Telegram не удерживает
доменные блокировки и не может откатить уже сохранённую заявку; цена этой
развязки — eventual consistency карточки и обязательная идемпотентность job.
