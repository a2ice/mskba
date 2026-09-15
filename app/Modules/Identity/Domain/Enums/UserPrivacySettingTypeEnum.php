<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserPrivacySettingTypeEnum: string
{
    case DISCOVERABILITY = 'discoverability';
    case CONTACTS = 'contacts';
    case MESSAGES = 'messages';
    case GROUP_INVITATIONS = 'group_invitations';
    case PROFILE = 'profile';
    case AVATAR = 'avatar';
    case ROLE_PLAYER = 'role_player';
    case ROLE_COACH = 'role_coach';
    case ROLE_REFEREE = 'role_referee';
    case ROLE_STATISTICIAN = 'role_statistician';
    case ROLE_MEDIA = 'role_media';
    case ROLE_VENUE_RELATED = 'role_venue_related';
    case ROLE_ORGANIZER = 'role_organizer';
    case PLAYER_CHARACTERISTICS = 'player_characteristics';
    case PLAYER_TEAMS = 'player_teams';
    case PLAYER_SECTIONS = 'player_sections';
    case PLAYER_GAMES = 'player_games';
    case PLAYER_TOURNAMENTS = 'player_tournaments';
    case COACH_SECTIONS = 'coach_sections';
    case MEDIA_MATERIALS = 'media_materials';
    case VENUE_VENUES = 'venue_venues';
    case REFEREE_EVENTS = 'referee_events';
    case STATISTICIAN_EVENTS = 'statistician_events';

    public function label(): string
    {
        return match ($this) {
            self::DISCOVERABILITY => 'Видимость в поиске',
            self::CONTACTS => 'Показывать мои контакты',
            self::MESSAGES => 'Кто может писать мне сообщения',
            self::GROUP_INVITATIONS => 'Кто может добавлять меня в группы',
            self::PROFILE => 'Публичная страница профиля',
            self::AVATAR => 'Аватар в публичном профиле',
            self::ROLE_PLAYER => 'Страница игрока',
            self::ROLE_COACH => 'Страница тренера',
            self::ROLE_REFEREE => 'Страница судьи',
            self::ROLE_STATISTICIAN => 'Страница статиста',
            self::ROLE_MEDIA => 'Страница медиа',
            self::ROLE_VENUE_RELATED => 'Страница представителя площадки',
            self::ROLE_ORGANIZER => 'Страница организатора мероприятий',
            self::PLAYER_CHARACTERISTICS => 'Игровые характеристики',
            self::PLAYER_TEAMS => 'Команды игрока',
            self::PLAYER_SECTIONS => 'Участие игрока в секциях',
            self::PLAYER_GAMES => 'Сыгранные игры',
            self::PLAYER_TOURNAMENTS => 'Турниры игрока',
            self::COACH_SECTIONS => 'Секции тренера',
            self::MEDIA_MATERIALS => 'Материалы медиа',
            self::VENUE_VENUES => 'Площадки под управлением',
            self::REFEREE_EVENTS => 'Мероприятия судьи',
            self::STATISTICIAN_EVENTS => 'Мероприятия статиста',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DISCOVERABILITY => 'Кто сможет находить вас в поиске и списках выбора пользователей. Статус аккаунта, подтверждение, блокировка и удаление проверяются отдельно.',
            self::CONTACTS => 'Кому доступны опубликованные вами контактные данные.',
            self::MESSAGES => 'Кто сможет начать с вами личную переписку.',
            self::GROUP_INVITATIONS => 'Кто сможет приглашать вас в команды, чаты и другие группы.',
            self::PROFILE, self::AVATAR, self::ROLE_COACH, self::COACH_SECTIONS => 'Видимость в профиле. Имя, аватар, роль тренера и открытые секции действующего тренера всегда публичны; остальные данные сохраняют свои ограничения.',
            default => 'Кому доступна эта страница или блок профиля. Видимость блока не открывает закрытую страницу.',
        };
    }

    public function defaultVisibility(): UserPrivacyVisibilityEnum
    {
        return match ($this) {
            self::DISCOVERABILITY, self::GROUP_INVITATIONS, self::PROFILE, self::AVATAR => UserPrivacyVisibilityEnum::EVERYONE,
            default => UserPrivacyVisibilityEnum::NOBODY,
        };
    }
}
