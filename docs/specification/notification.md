# Notification

## Оглавление

- [Назначение](#назначение)
- [Граница контекста](#граница-контекста)
- [UserNotification](#usernotification)
- [События и listeners](#события-и-listeners)
- [Центр уведомлений](#центр-уведомлений)
- [Подписки и отписки](#подписки-и-отписки)
- [Развитие модели](#развитие-модели)

## Назначение

`Notification` - отдельный доменный контекст для пользовательских in-app уведомлений.

Контекст нужен для событий, которые приходят из разных модулей: `Identity`, `Contact`, будущих профилей, площадок, договоров, событий и магазина.

## Граница контекста

Код контекста расположен в `App\Modules\Notification`.

Текущие доменные модели:

- `UserNotification`.

Текущие enum:

- `UserNotificationStatusEnum`;
- `UserNotificationSourceEnum`;
- `UserNotificationTypeEnum`.

Текущие application DTO:

- `CreateUserNotificationDTO`.

Текущие use case:

- `CreateUserNotificationHandler`;
- `CountNewUserNotificationsHandler`;
- `ListUserNotificationsHandler`;
- `MarkAllUserNotificationsAsReadHandler`;
- `MarkUserNotificationAsReadHandler`.

Сообщения, переписка и обращения поддержки не входят в этот контекст. Для них нужен отдельный bounded context, даже если пользовательский экран в будущем останется единым.

## UserNotification

`UserNotification` хранит in-app уведомление конкретного пользователя.

Таблица `user_notifications`:

- `user_id` - получатель уведомления;
- `type` - тип уведомления: `system`, `security`, `profile`, `reminder`;
- `status` - статус чтения: `new`, `read`;
- `title` - короткий заголовок;
- `body` - текст уведомления;
- `action_url` - ссылка действия, nullable;
- `payload` - JSON для технического контекста, nullable;
- `read_at` - дата прочтения, nullable;
- timestamps.

`payload.source` должен заполняться значением `UserNotificationSourceEnum`, а не произвольной строкой. Это сохраняет возможность различать конкретный сценарий уведомления без разрастания `UserNotificationTypeEnum`.

Статус `deleted` в первой итерации не реализован. Перед добавлением нужно определить семантику: скрытие пользователем, архивирование, системная очистка по retention policy или админское удаление.

## События и listeners

`NotificationServiceProvider` регистрирует listeners через Laravel event dispatcher.

Текущие события, на которые подписывается `Notification`:

- `App\Modules\Identity\Domain\Events\UserRegistered` - создается после регистрации пользователя в `Identity`;
- `App\Modules\Contact\Domain\Events\UserContactConfirmed` - создается после успешного подтверждения контакта пользователя в `Contact`.

Текущие listeners:

- `CreateWelcomeNotification`;
- `CreateContactConfirmedNotification`.

События принадлежат исходным bounded context, где произошло бизнес-действие. `Notification` не определяет чужие события, а только подписывается на них и создает собственные `UserNotification`.

События публикуются после завершения бизнес-действия, чтобы уведомление не создавалось для неуспешной операции.

## Центр уведомлений

Пользовательский экран находится в личном кабинете:

- route `account.notifications`;
- view `theme::pages.account.notifications`;
- пункт меню "Центр уведомлений".

Экран показывает только уведомления текущего пользователя. Отметка о прочтении проверяет владельца уведомления.

В меню личного кабинета рядом с пунктом "Центр уведомлений" отображается счетчик новых уведомлений. В верхней кнопке аккаунта отображается компактный круглый счетчик: числа `1`-`9` показываются явно, значение больше `9` отображается как `...`. На странице центра уведомлений есть массовое действие "Отметить все прочитанными".

В карточке уведомления статус и тип показываются icon-only бейджами с `title` и `aria-label`. Текстовые значения статуса и типа остаются в enum `label()` и используются как доступное описание.

## Подписки и отписки

Подписки и отписки в первой реализации отсутствуют.

При будущем добавлении настроек подписка должна относиться к типу уведомлений или каналу доставки, а не к конкретному экземпляру `UserNotification`. Критичные security-уведомления могут быть неотключаемыми.

## Развитие модели

Следующие шаги должны добавляться отдельными задачами:

- счетчик новых уведомлений в header;
- фильтры и вкладки центра уведомлений;
- статус удаления или архивирования;
- `NotificationPreference` для подписок и каналов;
- delivery-каналы email, Telegram, WebSocket, push;
- reminder policy/strategy для последовательных напоминаний;
- объединение интерфейса с будущим BC сообщений.
