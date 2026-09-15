# Публичная проекция пользователя

## Маршруты и доступ

GET `/users/{username}`, `/users/{username}/{role}`, `/users/{username}/preview` обслуживаются `PublicUserProfileController` в Identity. Роль ограничена enum участия, должна быть активна и доступна по приватности. Закрытые, заблокированные и удалённые ресурсы возвращают 404. Для прежних аккаунтов без username используется резервный `/users/id/{id}`; при появлении username он перенаправляет на основной адрес. Объединённая identity перенаправляет страницу на канонический профиль.

`PublicUserProfileService` формирует явный набор полей. API попапа отдаёт только имя, URL аватара и страницы, публичную подпись роли, признак публичного тренера и открытые секции. Модели User/Profile/Contact целиком не сериализуются. Ответы страниц/API используют `Cache-Control: private, no-store`, поскольку зависят от посетителя.

Приватность проверяется `UserPrivacyAccessService` с каноническими identity. Новые enum-типы не требуют миграции: type хранится строкой, отсутствующие строки используют defaults. Старые клиенты могут отправить четыре прежние настройки; пропущенные новые настройки сохраняются. Форма перечисляет все enum cases.

## Публичный минимум тренера

Требуются включённый контур секций, активная роль coach, SportsSection status=active и membership со sport_roles=coach. Контракт имеет family=membership, status=active, starts_at отсутствует либо наступил, expires_at отсутствует либо строго в будущем. Учитываются канонические identity IDs. Исключение открывает только базовую идентичность, аватар, роль coach и открытые секции; PROFILE не становится глобально разрешённым для других ролей/блоков.

Карточки игроков требуют PROFILE, ROLE_PLAYER и PLAYER_SECTIONS; AVATAR проверяется отдельно. Проверки выполняются до вывода списка, счётчик активных учеников не меняется.

## Предметные выборки

Игровые поля имеют allowlist, внутренние комментарии, extra и служебные оценки не публикуются. Команды ограничены competitionEligible и действующим player membership. История использует `GameRosterEntry.game.event`: в текущей таблице roster нет устаревшего event_id. Требуются played, completed game, public event в published/completed статусе. Турниры confirmed, entry active и member содержит identity; прошлое определяется tournament_closed_at либо ends_on раньше сегодня.

Другие роли читают published ContentItem, active VenueOwnership с confirmed Venue и confirmed/accepted EventParticipant публичного события. Черновики и частные файлы не участвуют.

## Интерфейс и запись

Попап переиспользует embedded-entity-preview: отдельный контейнер пользователя, textContent, сброс прежних данных/карты, AbortController и проверка текущего запроса. Попап площадки сохраняет вывод и карту. Общая карточка pages/users/person-card читает активный Profile avatar с Telegram/VK fallback.

Организатор не видит CTA записи; ManageSportsSectionJoinRequestHandler повторяет запрет внутри транзакции после блокировки секции. Публичное чтение новых lock-порядков не вводит.

Проверки: PublicUserProfileTest, AccountPrivacySettingsTest, AccountAvatarTest, SportsSection suite, production build и GitHub CI. [Продуктовые правила](../project/public-user-profile.md).
