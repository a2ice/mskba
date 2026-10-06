<?php

namespace App\Modules\Rewards\Application\Data;

final readonly class RewardMechanismContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $businessFactType,
        public string $businessFactKey,
        public array $payload = [],
    ) {}
}
