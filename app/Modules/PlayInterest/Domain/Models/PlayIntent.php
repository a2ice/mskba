<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayIntent extends Model
{
    protected $fillable = [
        'user_id', 'venue_id', 'client_request_id', 'name', 'include_similar_venues', 'center_latitude',
        'center_longitude', 'radius_km', 'cadence', 'weekdays', 'dates', 'windows',
        'period_from', 'period_until', 'formats', 'duration_minutes',
        'access_mode', 'venue_kind', 'amenity_preferences', 'notify_matches', 'notify_offers',
        'status', 'expires_at', 'show_on_venue', 'excluded_venue_ids',
        'notify_venue_news', 'game_delivery_channels', 'venue_delivery_channels',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'venue_id' => 'integer',
            'include_similar_venues' => 'boolean',
            'show_on_venue' => 'boolean',
            'excluded_venue_ids' => 'array',
            'center_latitude' => 'float',
            'center_longitude' => 'float',
            'radius_km' => 'integer',
            'weekdays' => 'array',
            'dates' => 'array',
            'windows' => 'array',
            'formats' => 'array',
            'amenity_preferences' => 'array',
            'duration_minutes' => 'integer',
            'notify_matches' => 'boolean',
            'notify_offers' => 'boolean',
            'notify_venue_news' => 'boolean',
            'game_delivery_channels' => 'array',
            'venue_delivery_channels' => 'array',
            'period_from' => 'immutable_date',
            'period_until' => 'immutable_date',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
