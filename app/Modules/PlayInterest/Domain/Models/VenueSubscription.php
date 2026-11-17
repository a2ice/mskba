<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VenueSubscription extends Model
{
    protected $fillable = ['user_id', 'venue_id', 'status', 'delivery_channels'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'venue_id' => 'integer',
            'delivery_channels' => 'array',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function venue(): BelongsTo { return $this->belongsTo(Venue::class); }

    public function channels(): array
    {
        return $this->delivery_channels ?: ['in_app'];
    }
}
