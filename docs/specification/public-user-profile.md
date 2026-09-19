# Публичная проекция пользователя

## Маршруты и доступ

GET `/users/{identifier}`, `/users/{identifier}/{role}`, `/users/{identifier}/preview` обслуживаются `PublicUserProfileController` в Identity. Resolver сначала ищет lower-case nickname, затем login/username. Канонический генератор `PublicUserProfileService::url()` использует nickname → username → `/users/id/{id}`; открытие прежнего username после появления nickname даёт 301 на nickname. Роль ограничена enum участия, должна быть активна и доступна по приватности. Закрытые, заблокированные и удалённые ресурсы возвращают 404. Объединённая identity перенаправляет страницу на канонический профиль.

`users.nickname` — nullable varchar(30) с unique index. PATCH `/account/nickname` находится под `web`, `auth` и throttle, нормализует строку в lower-case и проверяет regex `^[a-z][a-z0-9_]*$`, длину 3–30 и отсутствие коллизии как с nickname, так и с username другой identity. Пустое значение удаляет nickname. JSON-ответ содержит сохранённое значение и новый public URL; UI `/account` выполняет запрос через `account-nickname.js`.

Placeholder никнейма на `/account` не является общим статическим примером. `NicknameSuggestionService` строит персональную подсказку из имени и фамилии через латинскую slug-транслитерацию и показывает её только при отсутствии коллизии с nickname/username другой identity. Если персональный вариант занят или не строится, используется первый свободный нейтральный вариант: `court_king`, затем цифровые варианты `court_king1...`, при необходимости `court_king_1000...`. Подсказка не резервирует значение: PATCH всегда повторно проверяет фактическую доступность.

`PublicUserProfileService` формирует явный набор полей. API попапа отдаёт только имя, URL/состояние аватара и страницы, публичную подпись роли, признак публичного тренера и открытые секции. Модели User/Profile/Contact целиком не сериализуются. Ответы страниц/API используют `Cache-Control: private, no-store`, поскольку зависят от посетителя.

Приватность проверяется `UserPrivacyAccessService` с каноническими identity. Новые enum-типы не требуют миграции: type хранится строкой, отсутствующие строки используют defaults. Старые клиенты могут отправить четыре прежние настройки; пропущенные новые настройки сохраняются. Форма перечисляет все enum cases.

## Публичный каталог участников

`/participants`, `/players` и `/coaches` используют `PublicUserProfileService::catalogEntry()` и публикуют только явную projection: display name, разрешённый avatar, nickname при доступном PROFILE, публичные роли и URL профиля. В каталог не попадают удалённые, заблокированные, неподтверждённые или недоступные через DISCOVERABILITY canonical users.

Роль считается видимой, если она активна, PROFILE доступен текущему viewer и разрешён соответствующий `ROLE_*`. Для coach сохраняется существующее исключение публичного тренера с действующей открытой секцией. AVATAR проверяется отдельно. Результат зависит от viewer, поэтому каталог отвечает `Cache-Control: private, no-store`.

`/players` и `/coaches` — не отдельные модели данных: это тот же participant catalog с preset role. На `/participants` роль выбирается фильтром из `UserParticipationRoleEnum`.

## Публичный минимум тренера

Требуются включённый контур секций, активная роль coach, SportsSection status=active и membership со sport_roles=coach. Контракт имеет family=membership, status=active, starts_at отсутствует либо наступил, expires_at отсутствует либо строго в будущем. Учитываются канонические identity IDs. Исключение открывает базовую идентичность, роль coach и открытые секции, но не переопределяет AVATAR и не делает PROFILE глобально разрешённым для других ролей/блоков.

Если AVATAR запрещён конкретному viewer, `avatar_url=null`, `avatar_restricted=true`; Blade-карточка, публичная страница и embedded preview показывают стандартную иконку, а для restricted-варианта добавляют tooltip «Отображение аватара запрещено в настройках профиля». Если фото просто отсутствует при разрешённом AVATAR, placeholder не получает этот tooltip.

Карточки игроков требуют PROFILE, ROLE_PLAYER и PLAYER_SECTIONS; AVATAR проверяется отдельно. Проверки выполняются до вывода списка, счётчик активных учеников не меняется.

## Предметные выборки и подписи ролей

Игровые поля имеют allowlist. `player_profiles.comment` теперь является публичной пользовательской подписью роли player и при непустом значении заменяет `UserParticipationRoleEnum::description()`. `extra` и служебные оценки не публикуются. Другие роли используют стандартные enum-description до появления собственных публичных description-полей; `user_participation_roles.comment` не используется, поскольку это служебное происхождение назначения роли.

Команды ограничены competitionEligible и действующим player membership. История использует `GameRosterEntry.game.event`: требуются played, completed game, public event в published/completed статусе. Турниры confirmed, entry active и member содержит identity; прошлое определяется tournament_closed_at либо ends_on раньше сегодня.

Другие роли читают published ContentItem, active VenueOwnership с confirmed Venue и confirmed/accepted EventParticipant публичного события. Черновики и частные файлы не участвуют.

## Интерфейс и запись

Попап переиспользует embedded-entity-preview: отдельный контейнер пользователя, textContent, сброс прежних данных/карты, AbortController и проверка текущего запроса. Попап площадки сохраняет вывод и карту. Общая карточка pages/users/person-card использует ту же публичную проекцию аватара.

Организатор не видит CTA записи; ManageSportsSectionJoinRequestHandler повторяет запрет внутри транзакции после блокировки секции. Публичное чтение новых lock-порядков не вводит.

Проверки: AccountNicknameTest, NicknameSuggestionTest, PublicUserProfileTest, AccountPrivacySettingsTest, AccountAvatarTest, SportsSection suite, production build и GitHub CI. [Продуктовые правила](../project/public-user-profile.md).


## Собственный публичный профиль

При открытии своей canonical public-profile страницы авторизованному пользователю в sidebar показывается CTA «Перейти в аккаунт» → `/account`. Для других пользователей и гостей эта ссылка не выводится. Признак owner вычисляется controller-ом по canonical identity и не передаётся из клиентских параметров.


## Каталог участников vs приватность role-page

Публичный participant catalog использует `DISCOVERABILITY` как отдельное право присутствия пользователя в поиске/каталогах. Поэтому confirmed canonical user без participation roles допустим в `/participants`, а пользователь с активной ролью player/coach допустим в соответствующей role-выборке независимо от того, открыта ли его детальная role-page.

Это не открывает закрытые персональные данные. `PROFILE`, `AVATAR`, `ROLE_*` и требования personal-data-distribution consent по-прежнему применяются к содержимому карточки и целевой странице. При закрытом PROFILE каталог показывает только безопасный identifier аккаунта и не формирует кликабельную ссылку на закрытый профиль.
