# Task 191 — Acquisition onboarding / promo landing

## Source

Исходный пользовательский backlog-сценарий был назван `B043`: офлайн-промо возле баскетбольных площадок, A4-листовки с QR-кодом и отдельный вход на портал MSKBA.

> В текущем реестре проекта идентификатор backlog `B043` уже использован в task 188 для публичного профиля. Поэтому реализация ведётся как task 191, а исходная пометка сохранена здесь для трассировки.

## Цель

Сделать не одноразовую QR-страницу, а общий acquisition/onboarding слой, который позволяет:

- принимать переходы по QR-кодам у площадок;
- использовать тот же landing для контекстной рекламы, соцсетей, партнёров, referral и direct traffic;
- фиксировать источник до регистрации и не терять его после логина/создания аккаунта;
- отличать маркетинговый источник (`acquisition channel`) от технического способа создания аккаунта (`users.registration_channel`);
- подбирать onboarding и first actions по намерению пользователя;
- best-effort проверять, находится ли посетитель возле площадки, к которой привязана физическая QR-кампания.

## Маршруты

- `GET /join` — общий acquisition landing без обязательной кампании;
- `GET /join/{campaignCode}` — landing конкретной кампании;
- `POST /join/persona` — сохранить onboarding persona в текущем visit/session;
- `POST /join/location` — сохранить результат добровольной геопроверки;
- `GET /join/success` — персональный экран после регистрации/входа.

## Personas и роли

Acquisition persona хранится отдельно от роли аккаунта, потому что отвечает на вопрос «с каким намерением человек пришёл в этот flow».

Personas:

- `player` → participation role `player`;
- `coach` → participation role `coach`;
- `venue` → participation role `venue_related`;
- `organizer` → participation role `organizer`;
- `explore` → без автоматического назначения роли.

Task 191 добавляет `organizer` в `UserParticipationRoleEnum`. Это не системная роль и не permission. Она только описывает предметное участие пользователя; Gate/middleware создания объектов остаются source of truth для прав.

## Landing / wizard

Первый экран объясняет ценность MSKBA и предлагает существующему пользователю открыть обычную форму входа.

Шаг 1 — выбор сценария:

- Игрок: найти игры и тренировки, турниры, команды и людей для регулярной игры.
- Тренер: открыть секцию, набирать игроков, вести занятия и команду.
- Представитель площадки: добавить площадку, публиковать условия/расписание, принимать заявки на аренду.
- Организатор: создавать игры, тренировки и турниры, собирать участников и подбирать площадки.
- «Пока просто посмотрю»: регистрация без роли.

Шаг 2 — базовая регистрация через существующий `POST /register` и `RegisterUserHandler`.

Для `player`/`coach` появляется дополнительный шаг с полями профиля `birth_date` и `gender` (имя/фамилия optional), чтобы не создавать заведомо неполный профиль для текущего confirmation flow.

После успешной регистрации существующий auth flow автоматически авторизует пользователя и возвращает его в `/join/success`.

## Success screen

Экран `/join/success` сортирует первые действия по persona.

Пример для player:

1. найти игру или тренировку;
2. найти/создать команду;
3. участвовать в турнирах;
4. найти секцию и тренера.

Для действий, требующих подтверждения аккаунта, рядом показывается требование и ссылка на `account.confirmation` / FAQ «как подтвердить аккаунт».

## Attribution model

### `acquisition_campaigns`

Кампания содержит:

- `public_code` — безопасный внешний идентификатор для URL/QR;
- `name`;
- `channel`;
- nullable `venue_id`;
- `verification_radius_m`;
- период активности;
- `is_active`;
- metadata.

Поддерживаемые channels:

- `qr`;
- `context_ads`;
- `social`;
- `partner`;
- `referral`;
- `direct`;
- `other`.

Для общего `/join` channel определяется по UTM/referrer. Например `utm_medium=cpc` → `context_ads`, `utm_medium=social` → `social`.

### `acquisition_visits`

История append-only. Визиты не имеют ограничения `UNIQUE(user_id, venue_id)` — повторные переходы важны для аналитики и должны сохраняться.

Visit хранит:

- campaign;
- nullable canonical user;
- channel;
- UTM source/medium/campaign/content/term;
- выбранную persona;
- landing path и referrer;
- результат геопроверки;
- distance/accuracy;
- visited/linked timestamps.

IP-адрес и user-agent специально не сохраняются: для связи перехода с последующей регистрацией достаточно server-side session, а лишний fingerprint не даёт продуктовой ценности для этой задачи.

В session хранится ID текущего visit. До авторизации `user_id = null`; после обычного login, Telegram/VK login или регистрации visit связывается с canonical user на `/join/success`.

`users.registration_channel` не подменяется acquisition-каналом. Например пользователь из QR по-прежнему может иметь `SITE_FULL_REGISTRATION`, а источник `QR` остаётся в acquisition history.

## Геопроверка QR

Геолокация не может и не должна запрашиваться скрытно. Flow:

- если permission уже выдан браузеру — можно выполнить проверку автоматически;
- иначе на landing показывается явная кнопка «Подтвердить, что я здесь»;
- отказ/ошибка GPS никогда не блокируют регистрацию;
- raw latitude/longitude пользователя не сохраняются;
- сохраняются `location_status`, `location_accuracy_m`, `distance_to_venue_m`, `location_verified_at`.

Статусы:

- `not_requested`;
- `verified`;
- `mismatch`;
- `inaccurate`;
- `denied`;
- `unavailable`.

Для `verified` используется радиус кампании с учётом заявленной браузером GPS accuracy.

## Операционное создание кампании

Добавлена команда:

```bash
php artisan acquisition:campaign school-1794-a4 "Листовка у школы 1794" --channel=qr --venue=school-1794 --radius=250
```

Команда создаёт/обновляет campaign и выводит готовую ссылку `/join/{public_code}`. Существующая кампания обновляется только с `--force`.

## Безопасность / privacy

- внешний QR использует `public_code`, а не DB id;
- campaign code ограничен `A-Za-z0-9_-`, 2–64 символа;
- IP и user-agent не сохраняются в acquisition history;
- сырые координаты пользователя не сохраняются;
- геопроверка добровольна;
- attribution не выдаёт permissions и не меняет system role;
- organizer — participation-role, а не permission.

## Tests

Feature coverage:

- generic `/join` создаёт direct visit;
- campaign сохраняет marketing channel;
- persona сохраняется в visit;
- регистрация через текущий auth flow не меняет `registration_channel` на QR и затем связывает visit с canonical user;
- уже авторизованный пользователь связывается с visit без повторной регистрации;
- UTM `cpc` определяется как context advertising.

## Отдельный follow-up

Идею из исходного сценария про nullable «желаемый уровень игрока» для набора в секцию не следует смешивать с acquisition schema. Это отдельное доменное поле условий набора секции рядом с возрастом/годом рождения и требует отдельной задачи: модель + enum/справочник уровня + create/edit UI + каталог/заявки + backward compatibility. Рекомендуемый следующий task после 191.

## Status

- [x] architecture;
- [x] landing + wizard;
- [x] existing-account login path;
- [x] role-aware success screen;
- [x] generic campaign/channel tracking;
- [x] session → canonical user attribution;
- [x] QR venue geoverification contract;
- [x] organizer participation role;
- [x] campaign CLI;
- [x] feature tests added;
- [ ] CI green;
- [ ] merged to `main` / production deploy verified.
