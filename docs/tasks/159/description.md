# 159 — Ограничить хранение browser fingerprint и live-аналитики

## Контекст

Online presence хранится как короткоживущая Redis-проекция, однако связанные
SQL-таблицы `user_fingerprints` и `game_live_view_sessions` ранее не имели
retention policy. Кроме того, fingerprint и его связь с авторизованным
пользователем обновлялись на каждом web-запросе.

## Цель

Ограничить долгосрочный объём псевдонимных данных и частоту SQL-записей, не
изменяя пользовательский счётчик online и actor attribution.

## Решение

- срок хранения неактивных fingerprint и подробных live-сессий настраивается
  отдельно, по умолчанию составляет 90 дней;
- активность fingerprint и связь fingerprint/user записываются не чаще одного
  раза в 10 минут через атомарный cache throttle;
- ежедневная scheduler-команда удаляет устаревшие строки небольшими пачками;
- очистка live-сессий выполняется до fingerprint, внешние nullable-ссылки на
  fingerprint обнуляются правилами БД, а actor продолжает существовать;
- для `last_seen_at` добавляются индексы, пригодные для retention-выборок;
- диагностическая команда показывает общий объём, суточный прирост и число
  строк, уже вышедших за срок хранения; scheduler не выполняет полные `COUNT`.

## Конкурентный доступ

Throttle использует атомарный `Cache::add`. Очистка выбирает ID по возрастанию и
удаляет отдельными короткими запросами без общей длинной транзакции. Scheduler
защищён `onOneServer()` и `withoutOverlapping()`, поэтому экземпляры приложения
не запускают конкурирующие очистки. Строка, активность которой обновилась между
выборкой и удалением, повторно проверяется по cutoff в самом `DELETE`.

## Настройки

```dotenv
IDENTITY_FINGERPRINT_ACTIVITY_STORE=redis
IDENTITY_FINGERPRINT_ACTIVITY_WRITE_INTERVAL_SECONDS=600
IDENTITY_FINGERPRINT_RETENTION_DAYS=90
GAME_LIVE_HISTORY_RETENTION_DAYS=90
GAME_LIVE_PRESENCE_STORE=redis
TRACKING_PRUNE_BATCH_SIZE=1000
TRACKING_PRUNE_MAX_BATCHES=100
```

## Проверка

- повторный запрос в пределах throttle-окна не увеличивает SQL-счётчики;
- первая авторизация немедленно создаёт связь fingerprint/user;
- устаревшие fingerprint и live-сессии удаляются, свежие сохраняются;
- команда диагностики возвращает ожидаемые счётчики;
- полный backend test suite остаётся зелёным.
