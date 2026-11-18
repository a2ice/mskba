# Task 333 — Удаление своего аккаунта на сайте и в мобильном API

## Выполнено
- Перенос кнопки «Удалить аккаунт» на страницу «Настройки» в двух темах.
- Подтверждение стандартным нативным dialog; в `mskba_app` применяется `mskba-modal`, обычный window.confirm удалён.
- Общий `DeleteOwnAccountHandler` атомарно soft-delete-ит canonical identity и aliases. Все Sanctum-токены отзываются, связанные бронирования, переписки, финансовые документы не уничтожаются.
- Существующий защищённый `DELETE /account` завершает web-сессию.
- Новый `DELETE /api/v1/me` защищён bearer-only, требует `account:delete`, ограничен throttle 5/мин, отвечает JSON 200.
- Новым мобильным токенам выдаётся право `account:delete` (старым требуется повторная авторизация).
- Публичные запросы к профилям и каталогу уже опираются на Eloquent SoftDeletes: удалённый профиль недоступен.

## Проверка
- PHPUnit feature tests: `DeleteOwnAccountTest`, `MobileAuthApiTest`; CI перед merge в main.
- Продакшн-проверка только на специально созданном тестовом аккаунте, реальные аккаунты не удалять.

[Контракт API](../../api/mobile-account-deletion.md)
