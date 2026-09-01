<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['coordination_id', 'user_id', 'joined_at', 'left_at'])]
class VenueRentalCoordinationParticipant extends Model
{
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(VenueRentalCoordination::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }
}
