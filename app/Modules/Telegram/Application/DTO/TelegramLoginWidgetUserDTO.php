<?php

namespace App\Modules\Telegram\Application\DTO;

final readonly class TelegramLoginWidgetUserDTO
{
    /**
     * @param  array<string, mixed>  $rawUser
     */
    public function __construct(
        public int $id,
        public ?string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $photoUrl,
        public int $authDate,
        public array $rawUser,
    ) {}
}
