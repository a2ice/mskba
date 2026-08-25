# 011 — Истечение hold и scheduler

## Цель
Надёжно освобождать неоплаченные слоты без ручного вмешательства и двойных переходов.

## Статус
Выполнена в `feature/115`.

## Доменные изменения
HELD истекает после эффективного protection deadline, если не подтверждён и нет действующего платёжного окна.

## Миграции
Индексы по `(status, hold_expires_at)` и payment deadline; хранение `expired_at/reason`.

## Handlers и сервисы
`ExpireBookingIfDue` блокирует запись, повторно читает deadlines/status и делает условный переход.

## Права
Системная команда, недоступная пользователю напрямую; ручной запуск только superadmin с аудитом.

## UI/API
Клиент показывает серверное время и следующий deadline, но не вычисляет статус самостоятельно.

## События и jobs
Scheduler `onOneServer`, выборка небольшими batch, отдельные idempotent jobs, retry/backoff.

## Тесты
Job одновременно с confirm/extension/payment callback, два workers, timezone, повторный запуск.

## Обратная совместимость
Legacy записи без deadline не истекают автоматически до миграции/нормализации.

## Критерии приёмки
- устаревшая job не портит продлённую/подтверждённую бронь;
- освобождение слота наблюдаемо;
- batch не держит длинную транзакцию.

## Результат выполнения

- `venue-booking:expire-due` раз в минуту выбирает до 100 (настраиваемо до
  500) rental booking со статусом `HELD` и наступившим
  `effective_protection_until`; scheduler использует `onOneServer` и
  `withoutOverlapping`;
- batch выполняет только короткий индексный read и ставит отдельную
  `ExpireVenueBookingIfDueJob` на каждый кандидат, не удерживая общую
  транзакцию и блокировки на всём наборе;
- job сохраняет observed booking version и deadline, перед командой повторно
  читает status/version/deadline, а `ExpireVenueBookingHandler` ещё раз
  проверяет их под установленными locks `venue → booking`;
- idempotency key детерминирован из booking/version/deadline. Два worker-а или
  retry не создают второй transition/outbox; подтверждённая или продлённая
  бронь распознаётся как stale job и остаётся неизменной;
- Expire handler теперь принимает только `SYSTEM` actor. Пользовательского HTTP
  endpoint нет; ручной операционный запуск выполняется той же console-командой
  и оставляет system actor в timeline;
- успешная команда атомарно переводит booking в `EXPIRED`, записывает reason и
  `terminal_at` в существующий append-only timeline и создаёт transactional
  outbox event. Отдельные `expired_at/reason` не добавлялись, потому что они
  дублировали бы уже канонические `terminal_at` и transition reason;
- новая миграция не потребовалась: индекс `(status,
  effective_protection_until)` был добавлен в 005 именно для этой выборки, а
  будущий payment deadline входит в единый effective deadline;
- Cache metrics `scheduled/completed/stale/failed` дают наблюдаемость
  scheduler-а; JSON/read UI возвращает `server_time` и
  `effective_protection_until`, не вычисляя статус на клиенте;
- legacy rows, `REQUESTED`, future/null deadline и выключенный `rental_flow`
  scheduler не затрагивает.

Тесты покрывают batch limit/filtering, повтор двух workers, stale extension и
confirmation, timezone, system-only authorization, feature flag, один
timeline/outbox effect и серверный deadline в read API.

Гонка job с Confirm/Extension решается optimistic version плюс повторной
проверкой deadline под mutex. Это optimistic concurrency: дорогая блокировка не
держится между scheduler scan и worker, а устаревшая работа безопасно
отбрасывается на границе агрегата.
