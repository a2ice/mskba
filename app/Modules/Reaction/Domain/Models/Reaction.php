<?php

namespace App\Modules\Reaction\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Domain\Enums\ReactionActorTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subject_type',
    'subject_id',
    'actor_type',
    'actor_id',
    'user_id',
    'value',
    'source',
    'source_occurred_at',
    'source_sequence',
    'source_metadata',
])]
final class Reaction extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'subject_type' => ReactionSubjectTypeEnum::class,
            'actor_type' => ReactionActorTypeEnum::class,
            'value' => ReactionValueEnum::class,
            'source' => ReactionSourceEnum::class,
            'source_occurred_at' => 'immutable_datetime',
            'source_sequence' => 'integer',
            'source_metadata' => 'array',
        ];
    }
}
