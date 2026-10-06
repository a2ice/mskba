<?php

namespace App\Modules\Rewards\Application\Data;

final readonly class RewardMechanismDecision
{
    public function __construct(
        public bool $eligible,
        public ?int $recipientUserId = null,
        public ?string $reason = null,
    ) {}

    public static function eligible(int $recipientUserId): self
    {
        return new self(true, $recipientUserId);
    }

    public static function rejected(?string $reason = null): self
    {
        return new self(false, null, $reason);
    }
}
