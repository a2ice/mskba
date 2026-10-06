<?php

namespace App\Modules\Rewards\Application\Contracts;

use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\RewardVersion;

interface RewardMechanism
{
    public function code(): string;

    public function label(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameterRules(): array;

    public function evaluate(
        RewardMechanismContext $context,
        RewardVersion $version,
    ): RewardMechanismDecision;
}
