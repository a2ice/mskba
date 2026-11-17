<?php

namespace App\Modules\Rewards\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'reward_id', 'mode', 'amount_minor'])]
final class VenueRewardOverride extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    protected function casts(): array
    {
        return [
            'venue_id' => 'integer',
            'reward_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }
}
