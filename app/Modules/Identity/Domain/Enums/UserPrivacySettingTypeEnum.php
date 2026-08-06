<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserPrivacySettingTypeEnum: string
{
    case DISCOVERABILITY = 'discoverability';
    case CONTACTS = 'contacts';
    case MESSAGES = 'messages';
    case GROUP_INVITATIONS = 'group_invitations';

    public function label(): string
    {
        return match ($this) {
            self::DISCOVERABILITY => 'Видимость в поиске',
            self::CONTACTS => 'Показывать мои контакты',
            self::MESSAGES => 'Кто может писать мне сообщения',
            self::GROUP_INVITATIONS => 'Кто может добавлять меня в группы',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DISCOVERABILITY => 'Кто сможет находить вас в поиске и списках выбора пользователей. Статус аккаунта, подтверждение, блокировка и удаление проверяются отдельно.',
            self::CONTACTS => 'Кому доступны опубликованные вами контактные данные.',
            self::MESSAGES => 'Кто сможет начать с вами личную переписку.',
            self::GROUP_INVITATIONS => 'Кто сможет приглашать вас в команды, чаты и другие группы.',
        };
    }

    public function defaultVisibility(): UserPrivacyVisibilityEnum
    {
        return match ($this) {
            self::DISCOVERABILITY, self::GROUP_INVITATIONS => UserPrivacyVisibilityEnum::EVERYONE,
            self::CONTACTS, self::MESSAGES => UserPrivacyVisibilityEnum::NOBODY,
        };
    }
}
