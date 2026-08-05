<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id',
    'user_id',
    'status',
    'reviewed_by_user_id',
    'reviewed_at',
])]
final class TeamJoinRequest extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TeamJoinRequestStatusEnum::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
