<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'role',
    'status',
    'assigned_at',
    'expires_at',
    'assigned_by',
    'assigner',
    'comment',
])]
class UserParticipationRole extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserParticipationRoleEnum::class,
            'status' => UserParticipationRoleStatusEnum::class,
            'assigner' => UserParticipationRoleAssignerEnum::class,
            'assigned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
