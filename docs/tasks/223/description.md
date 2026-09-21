# 223 — Исправить 500 после перевода бонусов и синхронизировать enum уведомлений в PostgreSQL

## Симптом

Первый production-перевод bonus-средств на другого пользователя завершался
страницей `500 Server Error` на `/account/wallet/transfers`.

## Причина

Task 218 добавил PHP enum case `UserNotificationTypeEnum::FINANCE`, но production
PostgreSQL уже имел созданный ранее CHECK constraint `user_notifications_type_check`,
в котором значения `finance` ещё не было.

Тесты этого не поймали, потому что CI использует SQLite in-memory и при каждой
запуске создаёт `user_notifications` заново уже из актуального PHP enum.

Сам перевод выполняется и коммитится раньше создания notification. Поэтому
production мог уже успешно переместить деньги, а затем упасть на INSERT
финансового уведомления и показать отправителю ложный 500.

## Исправление

- отдельная migration синхронизирует допустимые значения `user_notifications.type`
  с актуальным `UserNotificationTypeEnum`;
- PostgreSQL CHECK constraint пересоздаётся с `finance`;
- для MySQL обновляется ENUM;
- SQLite не требует действия, потому что fresh schema уже строится из актуального enum;
- failure notification после завершённого transfer больше не превращает перевод
  в 500: исключение логируется, sender получает success redirect и явное warning,
  что перевод уже выполнен и повторять его нельзя.

## Production safety

После deploy нужно проверить историю кошелька отправителя до повторной отправки:
первый перевод мог уже попасть в ledger несмотря на 500.

## Проверка

- migration применима на production PostgreSQL;
- CI создаёт fresh schema и остаётся зелёным;
- существующие transfer tests проходят;
- frontend build проходит.

## Статус

Выполнено и влито в `main` через PR #253,
merge commit `990b0df85dff81224771e3563795a767ed9b04ad`.
