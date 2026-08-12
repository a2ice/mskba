<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserRegistrationChannelEnum: string
{
    case SITE_CONTACT_FIRST = 'site_contact_first';
    case SITE_FULL_REGISTRATION = 'site_full_registration';
    case TELEGRAM_CHAT = 'telegram_chat';
    case TELEGRAM_MINI_APP = 'telegram_mini_app';
    case TELEGRAM_WEB = 'telegram_web';
    case TOURNAMENT_ON_SITE = 'tournament_on_site';
    case OTHER = 'other';
    case SEED = 'seed';

    public function label(): string
    {
        return match ($this) {
            self::SITE_CONTACT_FIRST => 'Регистрация через контакт',
            self::SITE_FULL_REGISTRATION => 'Полная регистрация',
            self::TELEGRAM_CHAT => 'Telegram-чат',
            self::TELEGRAM_MINI_APP => 'Telegram Mini App',
            self::TELEGRAM_WEB => 'Вход через Telegram',
            self::TOURNAMENT_ON_SITE => 'Быстрая регистрация на турнире',
            self::OTHER => 'Другое',
            self::SEED => 'Сидирование базы данных',
        };
    }
}
