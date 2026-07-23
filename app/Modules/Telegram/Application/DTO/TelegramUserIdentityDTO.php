<?php

namespace App\Modules\Telegram\Application\DTO;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;

final readonly class TelegramUserIdentityDTO
{
    /**
     * @param  array<string, mixed>  $rawData
     */
    public function __construct(
        public int $id,
        public ?string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $languageCode,
        public ?string $photoUrl,
        public array $rawData,
        public string $source,
        public UserRegistrationChannelEnum $registrationChannel,
        public bool $authenticated = false,
    ) {}
}
