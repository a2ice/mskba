<?php

namespace App\Modules\Rewards\Application\Data;

final readonly class RewardMechanismDecision
{
    public function __construct(
        public bool $eligible,
        public ?int $recipientUserId = null,
        public ?string $reason = null,
        public ?int $amountMinorOverride = null,
    ) {}

    public static function eligible(int $recipientUserId, ?int $amountMinorOverride = null): self
    {
        return new self(true, $recipientUserId, null, $amountMinorOverride);
    }

    public static function rejected(?string $reason = null): self
    {
        return new self(false, null, $reason);
    }
}
