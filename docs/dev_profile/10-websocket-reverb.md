# 10. WebSocket, Reverb, Laravel Echo

[← Оглавление](README.md) · [Далее →](11-docker-cicd.md)

## Что происходит технически

**WebSocket** — постоянное двустороннее соединение, позволяющее серверу отправить событие сразу, без постоянного опроса HTTP. **Laravel Reverb** — WebSocket-сервер совместимый с экосистемой Laravel Broadcasting; **Laravel Echo** — JS-клиентская абстракция подписки на каналы, **Pusher JS** — транспорт для совместимого протокола.

~~~text
Business action
   → CreateUserNotificationHandler
   → UserNotificationCreated
   → BroadcastUserNotificationCreated
   → UserNotificationCreatedBroadcast (ShouldBroadcastNow)
   → Reverb
   → PrivateChannel users.{id}
   → Echo subscribePrivate('.notification.created')
   → обновить счётчик / показать toast
   → HTTP sync при reconnect/focus (истина в БД)
~~~

## Где в коде

- [Broadcast payload](../../app/Modules/Notification/Infrastructure/Broadcasting/UserNotificationCreatedBroadcast.php) — implements ShouldBroadcastNow, broadcastOn PrivateChannel('users.' + ID), broadcastAs notification.created, broadcastWith notification/unread_count.
- [Регистрация каналов](../../routes/channels.php) — users.{id}, venue-bookings.{publicId}, venue-booking-conversations.{publicId}.
- [Разрешение доступа к user-каналу](../../app/Modules/Notification/Infrastructure/Broadcasting/UserNotificationChannel.php) — авторизация подписки.
- [Echo bootstrap](../../resources/js/realtime.js) — runtime config, connected/disconnected states, subscribePublic/subscribePrivate.
- [Подписка UI](../../resources/themes/mskba_app/js/notification-counts.js) — обновляет счетчики и toasts.
- [Config сервера](../../config/reverb.php) и [Compose](../../compose.yaml).

## Почему PrivateChannel критичен

Название канала не секрет. Если клиент напишет users.42, этого недостаточно: серверный callback в routes/channels.php должен проверить, что запросу **разрешён** этот канал. То же касается чата бронирования; нельзя полагаться на скрытие идентификатора в JS.

~~~text
Echo.private('users.42')
    │ HTTP auth запрос /broadcasting/auth
    ↓ Laravel проверяет пользователя и access policy
       ├─ разрешено → подписка
       └─ отказ → никаких событий
~~~

**Не путай с end-to-end encryption:** приватность канала — авторизация доступа, не отдельная гарантия сквозного шифрования payload.

## Почему не полагаться только на WebSocket

Сокет может отвалиться при переключении сети, засыпании телефона, обновлении вкладки или рестарте Reverb. В [notification-counts.js](../../resources/themes/mskba_app/js/notification-counts.js) есть HTTP synchronize(): при загрузке, фокусе, возврате вкладки и восстановлении соединения счётчик и сообщения сверяются с **авторитетной БД**. Дополнительно revision/syncSerial не позволяют старому HTTP-ответу перетереть новую информацию.

**Паттерн:** push для быстрой реакции + pull/reconciliation для корректности.

## Браузерная конфигурация

[realtime.js](../../resources/js/realtime.js) читает публичный Reverb key из метатега, переданного сервером, с fallback на Vite vars. [CI](../../.github/workflows/ci.yml) специально собирает frontend с пустыми локальными Reverb-переменными, чтобы не зашить localhost/test key в dev-бандл.

**Вопрос «Почему нельзя положить secret в VITE_?»** VITE_-переменные вшиваются в публичный JS. Reverb public app key — не серверный secret, а auth/reverb secret публиковать нельзя.

## Reverb vs polling vs SSE

- Polling: проще, но регулярные лишние HTTP запросы и задержки.
- SSE: сервер → браузер; хорош для однонаправленного стрима, но не для всех двусторонних сценариев.
- WebSocket: двустороннее постоянное соединение; больше инфраструктуры и нужна обработка reconnect.

Для уведомлений возможны альтернативы; WebSocket удобен, потому что тот же механизм пригоден для чатов и обновления состояния брони.

## Репетиция

**«Если Reverb не работает, пользователь потеряет уведомление?»** Нет, если уведомление сохранено в БД: браузер сверяет через HTTP. Reverb ускоряет появление, но не является хранилищем.

**«ShouldBroadcastNow vs ShouldBroadcast?»** Now публикует синхронно через broadcasting flow, ShouldBroadcast стандартно может поставить broadcasting job в очередь. Но бизнес-действия и фактическая доставка всё равно должны защищаться от сбоев.

**«Как защищён private channel?»** Server-side authorization на подписку; для чувствительных действий — повторная серверная авторизация HTTP-команды.
