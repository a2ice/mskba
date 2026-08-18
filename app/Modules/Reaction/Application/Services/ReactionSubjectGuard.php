<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ReactionSubjectGuard
{
    public function ensureReactable(ReactionSubjectTypeEnum $subjectType, int $subjectId): void
    {
        $exists = match ($subjectType) {
            ReactionSubjectTypeEnum::CONTENT => ContentItem::query()
                ->publishedInFeed()
                ->whereKey($subjectId)
                ->exists(),
            ReactionSubjectTypeEnum::VENUE,
            ReactionSubjectTypeEnum::PLAYER => false,
        };

        if (! $exists) {
            throw (new ModelNotFoundException)->setModel($subjectType->value, [$subjectId]);
        }
    }
}
