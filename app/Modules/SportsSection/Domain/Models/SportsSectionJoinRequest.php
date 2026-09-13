<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sports_section_id',
    'user_id',
    'status',
    'review_reason',
    'reviewed_by_user_id',
    'reviewed_at',
])]
final class SportsSectionJoinRequest extends Model
{
    use Auditable;

    public function sportsSection(): BelongsTo
    {
        return $this->belongsTo(SportsSection::class);
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
            'status' => SportsSectionJoinRequestStatusEnum::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
