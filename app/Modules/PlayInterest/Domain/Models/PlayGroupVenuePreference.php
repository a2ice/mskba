<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayGroupVenuePreference extends Model
{
    protected $table = 'play_group_venue_preferences';
    protected $fillable = ['opportunity_id','venue_id','user_id','rating','unsuitable','comment','windows'];
    protected function casts(): array
    {
        return ['rating'=>'integer','unsuitable'=>'boolean','windows'=>'array'];
    }
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlayOpportunity::class,'opportunity_id');
    }
}
