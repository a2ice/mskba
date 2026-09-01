<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'update_id', 'callback_id', 'coordination_id', 'telegram_user_id', 'action',
    'status', 'attempts', 'last_error', 'completed_at',
])]
final class TelegramVenueRentalUpdate extends Model
{
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(VenueRentalCoordination::class);
    }

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'telegram_user_id' => 'integer',
            'attempts' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
