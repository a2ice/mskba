# 010 — Attendance coordination V2

## Цель
После получения hold уточнять реальную явку, не превращая опрос в источник бронирования.

## Статус
Выполнена в `feature/115`.

## Доменные изменения
Attendance round принадлежит HELD booking, имеет deadline и ответы `YES|NO|MAYBE`; deadline не позже эффективного hold deadline.

## Миграции
Rounds и responses с unique `(round_id,user_id)`, статусом open/closed и агрегированными счётчиками.

## Handlers и сервисы
OpenRound, Respond, Close; повторный ответ обновляет собственный выбор идемпотентно.

## Права
Раунд открывает сторона заявителя; отвечать могут приглашённые участники.

## UI/API
Счётчики, список ответов с учётом privacy и предупреждение, что ответ не продлевает hold.

## События и jobs
AttendanceResponded/ThresholdReached/RoundClosed; автозакрытие по deadline.

## Тесты
Граница deadline, истёкший booking, повторный ответ, конкурентное закрытие.

## Обратная совместимость
V2 включается после стабильной V1 и не влияет на booking без раунда.

## Критерии приёмки
- раунд возможен только при действующем hold;
- его срок автоматически обрезается hold/payment deadline;
- результат носит информационный характер.

## Результат выполнения

- добавлен отдельный `VenueBookingAttendanceRound`, принадлежащий rental
  booking, и invitation/response-строки с уникальностью `(round_id,user_id)`;
  один booking может иметь историю раундов, но только один active round;
- Open повторно проверяет feature flag, requester actor, статус `HELD` и
  действующий `effective_protection_until`; requested deadline автоматически
  обрезается по этому сроку и никогда его не продлевает;
- список приглашённых фиксируется при открытии и принимает только уникальных
  подтверждённых canonical users. Порог `YES` валидируется относительно этого
  списка, а privacy ответов задаётся как `participants|organizer`;
- Respond доступен только приглашённому пользователю, под блокировкой обновляет
  его единственную строку и пересчитывает сохранённые YES/NO/MAYBE/PENDING
  counters. Повтор того же ответа не создаёт событие, смена ответа корректирует
  счётчики, threshold фиксируется и публикуется ровно один раз;
- ручное Close доступно requester-у, системное — scheduler/listener; повторное
  конкурентное закрытие идемпотентно. Команда
  `venue-booking:close-expired-attendance` запускается ежеминутно, а
  Confirmed/Cancelled/Expired/Rejected booking закрывает открытый раунд через
  lifecycle event;
- события RoundOpened/AttendanceResponded/ThresholdReached/RoundClosed
  публикуются after commit. Открытие создаёт каждому приглашённому in-app
  уведомление со ссылкой на ответ;
- account UI/API показывает deadline, threshold, агрегаты и разрешённый privacy
  список. На каждом экране и в JSON есть явный контракт `extends_hold=false` и
  предупреждение, что ответы не продлевают hold;
- весь HTTP/application/scheduler flow закрыт независимым `attendance_v2` и не
  меняет booking без раунда.

Проверки покрывают deadline clipping без изменения защиты booking, отсутствие
активного hold, requester ACL, повтор и смену ответа, одно threshold event,
privacy/IDOR, ручное и автоматическое закрытие, lifecycle close и выключенный
feature flag.

Все изменяющие операции соблюдают порядок
`venues → venue_bookings → venue_booking_attendance_rounds → responses`.
Lifecycle booking уже использует начало `venues → venue_bookings`, поэтому
одновременные expiry/confirm/respond не создают обратного цикла блокировок.
