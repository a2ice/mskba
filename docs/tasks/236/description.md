# 236 — Диагностика прав Yandex AI Studio

## Причина

После переключения генератора персонажа на Yandex AI production отвечает общей ошибкой
аутентификации/прав. Нужно получить точный HTTP status/provider code/message из production
network path, не раскрывая секретный API key.

## Реализовано

- команда `php artisan ai:yandex:smoke`;
- безопасный минимальный вызов Responses API через production credentials;
- в лог выводятся только status, request id, provider code/type/message, folder id и короткий SHA-256 fingerprint ключа;
- сам секрет ключа не выводится;
- deploy временно запускает smoke, когда активен `PLAYER_CHARACTER_AI_PROVIDER=yandex`;
- diagnostic не блокирует production deploy.
