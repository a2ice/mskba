<?php

namespace App\Modules\Reaction\Application\Data;

use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;

final readonly class ReactionSummary
{
    public function __construct(
        public int $likes = 0,
        public int $dislikes = 0,
        public ?ReactionValueEnum $viewerReaction = null,
    ) {}

    /** @return array{likes: int, dislikes: int, viewer_reaction: int|null} */
    public function toArray(): array
    {
        return [
            'likes' => $this->likes,
            'dislikes' => $this->dislikes,
            'viewer_reaction' => $this->viewerReaction?->value,
        ];
    }
}
