<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'revision', 'changed_by_actor_id',
    'previous_venue_id', 'next_venue_id', 'previous_court_id', 'next_court_id',
    'previous_scope', 'next_scope', 'previous_starts_at', 'previous_ends_at',
    'next_starts_at', 'next_ends_at', 'reason',
])]
final class EventVenueRevision extends Model
{
    public const UPDATED_AT = null;

    public function previousVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'previous_venue_id');
    }

    public function nextVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'next_venue_id');
    }

    public function previousCourt(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'previous_court_id');
    }

    public function nextCourt(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'next_court_id');
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'previous_starts_at' => 'immutable_datetime',
            'previous_ends_at' => 'immutable_datetime',
            'next_starts_at' => 'immutable_datetime',
            'next_ends_at' => 'immutable_datetime',
        ];
    }
}
