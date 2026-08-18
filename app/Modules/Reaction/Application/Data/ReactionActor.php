<?php

namespace App\Modules\Reaction\Application\Data;

use App\Modules\Reaction\Domain\Enums\ReactionActorTypeEnum;

final readonly class ReactionActor
{
    public function __construct(
        public ReactionActorTypeEnum $type,
        public string $id,
        public ?int $userId = null,
    ) {}
}
