<?php

namespace App\Modules\Reaction\Domain\Models;

use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'subject_type',
    'subject_id',
    'source',
    'source_key',
    'likes_count',
    'dislikes_count',
    'source_occurred_at',
    'source_sequence',
    'source_metadata',
])]
final class ReactionAggregate extends Model
{
    protected function casts(): array
    {
        return [
            'subject_type' => ReactionSubjectTypeEnum::class,
            'source' => ReactionSourceEnum::class,
            'likes_count' => 'integer',
            'dislikes_count' => 'integer',
            'source_occurred_at' => 'immutable_datetime',
            'source_sequence' => 'integer',
            'source_metadata' => 'array',
        ];
    }
}
