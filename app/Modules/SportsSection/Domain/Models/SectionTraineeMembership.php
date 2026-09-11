<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sports_section_id', 'user_id', 'status', 'status_reason', 'notes', 'joined_at', 'left_at'])]
class SectionTraineeMembership extends Model
{
    use Auditable;

    public function section(): BelongsTo
    {
        return $this->belongsTo(SportsSection::class, 'sports_section_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['status' => TraineeMembershipStatusEnum::class, 'joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }
}
