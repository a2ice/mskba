<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Infrastructure\Database\Factories\TrainingSessionFactory;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sports_section_id', 'created_by_actor_id', 'starts_at', 'ends_at', 'status',
    'cancellation_reason', 'price_override_minor', 'confirmed_price_minor',
    'confirmed_currency', 'venue_id', 'venue_court_id', 'event_id', 'confirmed_at',
    'completed_at', 'cancelled_at',
])]
class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): TrainingSessionFactory
    {
        return TrainingSessionFactory::new();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SportsSection::class, 'sports_section_id');
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function venueCourt(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TrainingSessionParticipant::class);
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(TrainingSessionCoach::class);
    }

    public function effectivePriceMinor(): ?int
    {
        return $this->confirmed_price_minor ?? $this->price_override_minor ?? $this->section?->single_session_price_minor;
    }

    protected function casts(): array
    {
        return [
            'status' => TrainingSessionStatusEnum::class,
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'price_override_minor' => 'integer',
            'confirmed_price_minor' => 'integer',
        ];
    }
}
