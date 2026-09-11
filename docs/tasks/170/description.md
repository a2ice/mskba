# 170 — Контур спортивных секций и занятий

## Оригинальное описание

Перенести B041 из внешнего backlog и добавить в MSKBA новый доменный контур
регулярных тренировок: `SportsSection → CoachMembership → TraineeMembership →
TrainingSession → SessionParticipant → Event`.

Секция должна хранить долгоживущие настройки, тренерский и ученический состав,
тарифы, контакты и фотографии. Занятие должно быть отдельным экземпляром во
времени со snapshot состава, места и подтверждённой цены и может иметь не более
одного связанного `Event` типа `training`.

Уточнение: `SportsSection` — долгоживущая сущность, которая может существовать
годами; `TrainingSession` — конкретное занятие в определённый интервал; `Event` —
единичное публичное/календарное представление занятия на портале. Эта граница
должна сохранить возможность последующего добавления регулярных правил
расписания, посещаемости, тренировочных планов, прогресса, задолженности и
абонементов.

## Подробное описание

### Граница домена

Новый модуль называется `SportsSection`, чтобы не вводить generic `Section`.
`SportsSection` является aggregate root и отвечает за долговременную идентичность,
статус, формат тренировок, базовое место, базовую цену, тренерские полномочия,
текущий состав и тарифы. `TrainingSession` — дочерняя сущность одного конкретного
занятия; правило по дням недели в эту задачу не входит и в будущем лишь создаёт
экземпляры `TrainingSession`.

`Event` не становится вторым источником настроек секции или расписанием
повторений. Для связанного занятия source of truth — `TrainingSession`; `Event`
является публичной проекцией. Дата, время, Venue/VenueCourt и lifecycle меняются
через application-сервисы занятия и синхронизируются с Event в одной транзакции.
Обычное редактирование этих полей связанного Event блокируется, чтобы два
независимых интерфейса не создавали расхождение. Разрешён только `EventTypeEnum::TRAINING`;
`game` и `game_training` не связываются. Состав занятия и публичные участники
Event остаются разными понятиями: первый фиксирует тренируемых, второй — публичные
ответы/участие и не меняет membership секции.

### Модель секции

`sports_sections`:

- `name`, nullable `description`, alias, creator actor;
- status `draft|active|paused|archived`;
- training mode `individual|small_group|team`;
- существующий `GameFormatEnum`, допустимы только `basketball_5x5`,
  `streetball_3x3`, `streetball_1x1`, default `basketball_5x5`;
- nullable primary Venue и VenueCourt с серверной проверкой принадлежности;
- pricing type `free|paid`, nullable `single_session_price_minor`, ISO currency;
- contact source `head_coach|section` и nullable `contact_notes`;
- soft delete и стандартные timestamps;
- ссылка `head_coach_membership_id` на одну активную тренерскую membership.

Последняя ссылка разделяет access level и спортивную роль: создатель одновременно
получает access level `owner` и становится `head_coach`, но позднее owner может
передать роль главного тренера другому действующему тренеру и остаться владельцем.
Единственность главного тренера обеспечивается одной ссылкой в aggregate root;
смена выполняется под блокировкой секции и проверяет целевую membership.

### Тренеры и полномочия

Параллельный ACL не создаётся. Расширяется существующий
`Contract/ContractMembership/ContractPermission`:

- новый scope `sports_section`;
- access levels `owner|manager`;
- все тренеры имеют sport role `coach`, а роль `head_coach` определяется ссылкой
  секции на membership;
- permissions: `section.manage`, `section.coaches.manage`,
  `section.trainees.manage`, `section.sessions.manage`, `section.pricing.manage`,
  `section.contacts.manage`.

Создать секцию может только подтверждённый активный пользователь с активной
доменной ролью coach. Подключить тренера можно только после той же серверной
проверки. Создание секции, owner-contract и назначение head coach атомарны.

### Тренируемые

`SectionTraineeMembership` — отдельная модель, а не pivot. Она хранит пользователя,
status `active|inactive`, nullable status reason/notes, `joined_at` и nullable
`left_at`. Пользователь должен быть подтверждённым активным player. Membership
не удаляется при деактивации и может быть восстановлена; модель и сервисы не
мешают в будущем добавить append-only историю статусов.

### Тарифы и деньги

`SectionPricingPlan` хранит название, `amount_minor`, ISO currency, nullable
количество занятий, nullable duration в днях и status `active|inactive`.
Секция может иметь несколько тарифов. Продажи, платежи, задолженность и выданные
абонементы не входят в Task 170. Все суммы используют integer minor units и
существующий способ форматирования валюты.

У занятия есть nullable override цены. Пока занятие planned, effective price
вычисляется как `price_override ?? section.single_session_price`. При переходе в
confirmed сохраняются `confirmed_price_minor` и currency snapshot; последующие
изменения секции не переписывают историю.

### Занятия и snapshot

`TrainingSession` хранит section, start/end, status
`planned|confirmed|completed|cancelled`, обязательную для cancelled причину,
nullable price override и подтверждённый snapshot цены, Venue/VenueCourt и
nullable уникальный Event. При создании текущие primary Venue/Court копируются в
занятие, поэтому дальнейшее изменение секции не меняет существующие занятия.
Конкретное занятие может получить валидный override места.

Текущий активный состав копируется в `TrainingSessionParticipant`; текущие
тренеры — в `TrainingSessionCoach`. После создания snapshot можно менять без
изменения membership секции. Participant-модель сразу допускает nullable будущий
attendance status, но UI посещаемости в эту задачу не входит.

### Контакты, медиа и интерфейс

Секция подключается к существующим polymorphic `Contact` и `Media` через morph
alias `sports_section`. Отдельная SectionContacts не создаётся. При contact source
`section` показываются публичные контакты секции, при `head_coach` — публичные
контакты текущего главного тренера. Типы контактов не расширяются. Галерея
использует `Media`, `WebpImageNormalizer`, featured image и существующую файловую
стратегию.

Первая версия включает публичные index/show и account-контур создания и
управления основными данными, тренерами, тренируемыми, тарифами, контактами,
фотографиями и занятиями. Новый контур закрывается feature flag до завершения
этапов и включается после профильных проверок.

### Безопасность и конкурентность

Все mutating handlers повторно проверяют confirmed account, coach/player role,
scope и permission, принадлежность VenueCourt, тип Event и IDOR. Смена head coach,
изменение состава и lifecycle занятия выполняются под блокировкой строки секции,
а при синхронизации Event соблюдают общий порядок блокировок `venue → event →
session/section`, чтобы не создать обратный порядок относительно booking/event
контуров. Для cancelled обязательна непустая причина.

### Проверки

Профильные domain/feature-тесты защищают перечисленные в B041 бизнес-инварианты:
создание и запреты по аккаунту/роли; owner + head coach; ACL тренеров и IDOR;
единственность/safe handover head coach; lifecycle тренируемого; snapshot состава;
наследование/override/snapshot цены и места; допустимый Event; причина отмены;
разрешение контактов; Media flow. Косметическая HTML/CSS-разметка PHPUnit-тестами
не фиксируется.

## Декомпозиция

- [001 — Доменный фундамент](subtasks/001-domain-foundation.md)
- [002 — Тренерские contracts и ACL](subtasks/002-coach-contracts-and-access.md)
- [003 — Тренируемые, тарифы, контакты и медиа](subtasks/003-section-resources.md)
- [004 — Занятия и snapshot состава](subtasks/004-training-sessions.md)
- [005 — Интеграция TrainingSession и Event](subtasks/005-event-projection.md)
- [006 — Публичный и account UI](subtasks/006-section-ui.md)
- [007 — Проверка, документация и запуск](subtasks/007-verification-and-launch.md)

## Статус

Реализовано и включено в `main` 11.09.2026. После первичной реализации проведён UX/product-pass: ручной ввод `Event ID` заменён публикацией Event-проекции из занятия, добавлены фильтры публичного каталога, зависимый выбор площадки/зала, редактирование занятий и более понятное управление составом. Профильные проверки дополнены тестами этих сценариев.
