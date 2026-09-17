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
- подбирать onboarding и первые действия по намерению и фактическим ролям пользователя;
- best-effort проверять, находится ли посетитель возле площадки, к которой привязана физическая QR-кампания.

## Маршруты

- `GET /join` — общий acquisition landing без обязательной кампании;
- `GET /join/{campaignCode}` — landing конкретной кампании;
- `POST /join/persona` — сохранить onboarding persona в текущем visit/session;
- `PATCH /join/roles` — self-service обновление participation roles уже авторизованного пользователя внутри onboarding;
- `POST /join/location` — сохранить результат добровольной геопроверки;
- `GET /join/success` — персональный финальный экран после регистрации/входа.

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

Первый экран не заставляет сразу выбирать роль. Он разделяет два основных сценария:

- `Присоединиться` — новый пользователь переходит к выбору persona и созданию аккаунта;
- `У меня уже есть аккаунт` — существующий пользователь открывает форму входа.

После `Присоединиться` отдельным шагом показываются варианты:

- Игрок: найти игры и тренировки, турниры, команды и людей для регулярной игры.
- Тренер: открыть секцию, набирать игроков, вести занятия и команду.
- Представитель площадки: добавить площадку, публиковать условия/расписание, принимать заявки на аренду.
- Организатор: создавать игры, тренировки и турниры, собирать участников и подбирать площадки.
- «Пока просто посмотрю»: регистрация без роли.

После выбора persona выполняется базовая регистрация через существующий `POST /register` и `RegisterUserHandler`.

Для `player`/`coach` появляется дополнительный шаг с полями профиля `birth_date` и `gender` (имя/фамилия optional), чтобы не создавать заведомо неполный профиль для текущего confirmation flow. Если серверная валидация регистрации возвращает ошибку, wizard возвращает пользователя на шаг аккаунта, потому что поля пароля не восстанавливаются после redirect.

### Уже существующий / авторизованный пользователь

После входа из `/join` пользователь возвращается не сразу на success, а обратно в onboarding. Возврат помечается как resume: исходный acquisition visit переиспользуется, связывается с canonical user и не заменяется вторым визитом из-за внутреннего `Referer`.

Для уже авторизованного пользователя вместо повторной регистрации показывается сворачиваемый блок текущих participation roles:

- без ролей — `Моя роль — не установлена`;
- одна роль — например `Моя роль — игрок`;
- две роли — например `игрок и тренер`;
- три и более — первая роль и счётчик вида `игрок и ещё 2`.

В раскрытом состоянии используются те же self-service toggle-компоненты, что и на `/account/roles`. Можно включить несколько ролей, отключить ненужные или оставить пользователя без активных participation roles. Сохранение выполняется AJAX через `PATCH /join/roles` и существующий `UpdateUserParticipationRolesHandler`.

Venue-backed campaign context и добровольная геопроверка остаются доступны и для авторизованного пользователя.

## Success screen

`/join/success` больше не выбирает одну «главную» persona для рекомендаций. Source of truth для финального экрана — фактически активные participation roles canonical user.

Для каждой активной роли выводится отдельная визуальная группа с eyebrow роли и блоком «Доступные действия». Например:

- игрок — найти игру/тренировку, секцию, команду;
- тренер — открыть секцию, провести тренировку, работать с командой;
- представитель площадки — добавить площадку, перейти к бронированиям и мероприятиям;
- организатор — создать мероприятие, организовать турнир, подобрать площадку;
- судья / статист / медиа — профильные переходы к мероприятиям и настройке своей роли.

Если активных ролей нет, success screen предлагает нейтральные открытые разделы: мероприятия, площадки и команды.

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

В session хранится ID текущего visit. До авторизации `user_id = null`; после login или регистрации visit связывается с canonical user. При возврате после login onboarding явно переиспользует текущий недавний visit той же кампании, чтобы внутренний переход не создавал отдельную attribution-запись.

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
- organizer — participation-role, а не permission;
- self-service изменение ролей в onboarding доступно только авторизованному пользователю и использует общий handler управления participation roles.

## Tests

Feature coverage защищает устойчивое поведение, а не косметический текст/разметку:

- generic `/join` создаёт direct visit;
- campaign сохраняет marketing channel;
- persona сохраняется в visit;
- регистрация через текущий auth flow не меняет `registration_channel` на QR и затем связывает visit с canonical user;
- уже авторизованный пользователь связывается с visit без повторной регистрации;
- login-resume переиспользует исходный visit вместо создания второго;
- авторизованный пользователь может обновить participation roles внутри onboarding;
- success получает полный набор активных participation roles, а не одну выбранную persona;
- UTM `cpc` определяется как context advertising.

## Отдельный follow-up

Идею из исходного сценария про nullable «желаемый уровень игрока» для набора в секцию не следует смешивать с acquisition schema. Это отдельное доменное поле условий набора секции рядом с возрастом/годом рождения и требует отдельной задачи: модель + enum/справочник уровня + create/edit UI + каталог/заявки + backward compatibility. Рекомендуемый следующий task после 191.

## Production verification

- PR #206 merged into `main`: `43da7682920799114302a3b677daeb92509df9ce`.
- Production deploy #729 completed successfully for that merge commit.
- A real HTTP smoke check of `https://mskba.ru/join` completed successfully after deploy and confirmed the new onboarding copy: «Привет и добро пожаловать на MSKBA», «Присоединиться», «У меня уже есть аккаунт».

## Status

- [x] architecture;
- [x] landing + wizard;
- [x] existing-account login path;
- [x] authenticated role editor;
- [x] multi-role success screen;
- [x] generic campaign/channel tracking;
- [x] session → canonical user attribution;
- [x] login resume without duplicate visit;
- [x] QR venue geoverification contract;
- [x] organizer participation role;
- [x] campaign CLI;
- [x] feature tests added;
- [x] CI green;
- [x] merged to `main` / production deploy verified.
