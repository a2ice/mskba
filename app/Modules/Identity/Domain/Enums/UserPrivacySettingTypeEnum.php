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
            self::DISCOVERABILITY => 'Общая видимость',
            self::CONTACTS => 'Показывать мои контакты',
            self::MESSAGES => 'Кто может писать мне сообщения',
            self::GROUP_INVITATIONS => 'Кто может добавлять меня в группы',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DISCOVERABILITY => 'Определяет, появляетесь ли вы в поиске и списках выбора пользователей.',
            self::CONTACTS => 'Кому доступны опубликованные вами контактные данные.',
            self::MESSAGES => 'Кто сможет начать с вами личную переписку.',
            self::GROUP_INVITATIONS => 'Кто сможет приглашать вас в команды, чаты и другие группы.',
        };
    }

    public function defaultVisibility(): UserPrivacyVisibilityEnum
    {
        return $this === self::DISCOVERABILITY
            ? UserPrivacyVisibilityEnum::EVERYONE
            : UserPrivacyVisibilityEnum::NOBODY;
    }
}
