<?php

namespace App\Modules\Notification\Application\DTO;

use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;

final readonly class CreateUserNotificationDTO
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public int $userId,
        public UserNotificationTypeEnum $type,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
        public ?array $payload = null,
    ) {}
}
