<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VenueSubscription extends Model
{
    protected $fillable = ['user_id', 'venue_id', 'status', 'delivery_channels',
        'name', 'criteria', 'notify_venue_news', 'play_intent_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'venue_id' => 'integer',
            'play_intent_id' => 'integer',
            'notify_venue_news' => 'boolean',
            'criteria' => 'array',
            'delivery_channels' => 'array',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function venue(): BelongsTo { return $this->belongsTo(Venue::class); }
    public function playIntent(): BelongsTo { return $this->belongsTo(PlayIntent::class); }

    public function displayName(): string
    {
        return $this->name ?: ($this->venue ? 'Подписка на «'.$this->venue->name.'»' : 'Подписка №'.$this->id);
    }

    public function channels(): array
    {
        return $this->delivery_channels ?: ['in_app'];
    }
}
