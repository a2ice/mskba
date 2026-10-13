# 036 — Единый контракт заявок/тредов: пилот ProfileChangeRequest

Статус: локальный пилот (2026-10-13). Без commit, push, deploy.

Утверждённый принцип: новое право на рассмотрение дает доступ только к заявкам, созданным после его выдачи. В старую заявку может пригласить lead, уже имеющий право работать с ней. Первый уполномоченный, взявший новую заявку, становится lead; lead приглашает reviewer (только при наличии разрешения), передает ему lead атомарно и может исключать reviewer. Один lead на тред. При исключении или потере разрешения тред остаётся в сообщениях для ранее участвовавшего лица, но переписка/действия сервером закрыты; показывается нейтральный текст без имени исключившего. Исключенные могут вновь войти только по новому приглашению, а не через восстановленный permission. Заявитель не может быть исключён. Все действия аудируются. Ни одному ранее не участвовавшему пользователю старая история не открывается автоматически.

Первый интегрируемый тип — ProfileChangeRequest; его существующая модель/сервис/события и бизнес-решения не заменяются. Общая оболочка предусматривает тип + ID заявки, участников, события и сообщения; остальные типы подключаются по одному после приёмки. Проверка доступа в SQL через EXISTS/ограниченные подзапросы; отсутствие утечек при прямом URL/POST/вложениях/приватности, N+1, большие JOIN.

Приёмка: заявка из профиля появляется отдельным тредом, обмен сообщениями, приглашение, передача lead и исключение, отзыв права и доступ без содержимого, сохранение системной истории без дублей, тесты прав доступа, отмены, блокировки и отзыва разрешения.

## Локальный результат 2026-10-13

**Статус: на функциональной и визуальной приёмке первого адаптера `ProfileChangeRequest`.**

- Добавлен абстрактный слой `request_threads`, `request_thread_memberships`, `request_thread_messages`, `request_thread_events`, сохраняющий существующие доменные заявки/события. Текущий pilot adapter `ProfileRequestThreadAccess` не меняет Team/Venue/Rental модели. Migration выполнена только на локальной PostgreSQL после резервной копии исходных заявок и их событий; текущая заявка №1 сохранилась и получила связанный тред. Prod/dev БД не затронуты.
- В «Сообщениях» отдельная карточка «Изменение данных №ID», ссылки из профиля/админки, события изменения статуса, переписка, уведомления (без копирования в системный тред). Проверка прав на всех GET/POST, inbox и admin list. Поддержаны первый lead, приглашение (с проверкой permission), атомарная передача главенства, исключение участника, отзыв разрешения, сохранение закрытой карточки, аудируемый emergency takeover прежнего lead. Личность исключившего намеренно не раскрывается.
- Отзыв права закрывает историю/POST и сбрасывает новые уведомления по тредам. Изменения счётчиков синхронизируются через существующий broadcast. Повторная выдача permission не возвращает прежний доступ без нового приглашения.
- SQL список построен на коррелированных `EXISTS`, подгрузка тредов и участников через индексируемый `WHERE IN`. Нагрузочный регрессионный тест с 24 тредами ограничивает число запросов профиля без декартовых JOIN. На данный момент вывод последних 100 профильных тредов — преднамеренное временное ограничение до единой cursor-пагинации; не считать пилот массовым production inbox.
- Проверены `php artisan view:cache`, PHP Pint (целевые файлы), JavaScript syntax, изолированная Vite build в `/tmp`, `git diff --check`, профильные, административные, сообщения/уведомления и security тесты (111 тестов/1029 утверждений на контрольном прогоне до заключительных укреплений; повторная финальная проверка необходима). Chrome UI под реальной авторизацией, мобильная компоновка и сценарии нескольких reviewer — пользовательская визуальная приёмка.

## После приёмки пилота

- Сперва разделить новые площадки / ревизии подтверждённых площадок как разные permissions и подключить `ModerationRequest` и существующие сообщения без миграции их истории в другую предметную таблицу.
- Далее `VenueOwnershipClaim` с файлами, затем `TeamJoinRequest` и `VenueBooking`, не теряя уже существующих бизнес-прав и индивидуальной приватности вложений.
- Поддержка курсорной пагинации всех request-threads, рост набора типов, бенчмарки PostgreSQL/индексы, политики realtime только для допущенных участников.

**До команды пользователя commit/push/PR/deploy не выполнять.**

## Local profile-request pilot outcome (2026-10-13)

Status: ready for local functional/visual review; other request types remain unchanged.

- Existing `ProfileChangeRequest` / event storage is unchanged. New indexed `request_threads`, `request_thread_memberships`, `request_thread_messages` and `request_thread_events` establish a reusable thread envelope. Migration backfills previous requests without editing their content.
- Account Messages presents profile requests as independent threads with timeline and replies. First reviewer to claim becomes lead, can invite eligible reviewers to historical requests, transfer lead atomically, or exclude reviewers. Applicant always retains access to own request.
- Removed/revoked reviewers still see a tombstone in Messages; the server withholds conversation and actions on every URL/POST. Generic removal explanation avoids revealing the identity of the person who excluded them. A later permission regrant does not reverse exclusion; new invitation is required.
- Admin decisions continue through the existing profile request service. Notifications are associated with the thread, not duplicated in the read-only system conversation. Unread counts are updated when access is removed.
- Query filters use nested EXISTS, bounded batches and eager loading. A 24-thread regression detects row duplication / N+1; initial list is capped to the newest 100 profile threads pending cursor pagination.
- Local PostgreSQL migration applied after a private backup of request tables; existing request #1 remains intact with a corresponding thread. No production or remote dev DB migrations were run.
- QA: 111 affected tests / 1029 assertions PASS on first full compatible-theme run, plus follow-up security tests; Blade and isolated Vite pass. User-facing browser acceptance still needed.

Next rollout AFTER pilot acceptance: distinguish create-venue versus edit-venue moderation permissions, then integrate existing venue moderation/ownership messages, team applications and rental bookings via adapters; keep original domain storage, audit and privacy policies. No commit, push or deployment without instruction.

## QA after chat recovery (2026-10-13)

- Added targeted notifications for claiming a request, inviting a reviewer, transferring lead, excluding a reviewer and rescuing a disqualified lead. Notifications are linked to the request thread, not duplicated in the general system conversation, and contain no proposed profile values or excluding actor identity.
- Repeated `claim` is idempotent: no duplicate membership event or notification. Opening a tombstone acknowledges its notice without loading private history; inbox preview distinguishes explicit exclusion from generic loss of access.
- Moved reviewer `claim` inside the decision's outer transaction, after validation. A failed approval now leaves no lead, member or audit event; regression test added.
- Final multi-module regression (`APP_THEME=mskba_dark`): **97 tests / 829 assertions PASS**. Pilot alone: **11 tests / 113 assertions PASS**. Blade view compilation PASS.
- Remaining: authenticated browser acceptance (desktop/mobile) and integrations for venue/team/booking via separate adapters. Local only: no commit, push, PR or deployment until instructed.
