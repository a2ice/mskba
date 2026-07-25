<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Infrastructure\Database\Factories\EventFactory;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'venue_id',
    'organizer_actor_id',
    'title',
    'alias',
    'type',
    'status',
    'visibility',
    'description',
    'result_description',
    'starts_at',
    'ends_at',
    'max_participants',
    'completed_at',
    'completed_by_actor_id',
    'cancelled_at',
    'cancelled_by_actor_id',
    'cancellation_reason',
    'participation_confirmation_version',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /** @param Builder<Event> $query */
    public function scopeWhereRouteIdentifier(Builder $query, string $identifier): Builder
    {
        if (preg_match('/^(\d+)-/', $identifier, $matches) === 1) {
            return $query->whereKey((int) $matches[1]);
        }

        return $query->where('alias', $identifier);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function organizerActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'organizer_actor_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(VenueBooking::class);
    }

    public function telegramPublication(): HasOne
    {
        return $this->hasOne(TelegramEventPublication::class);
    }

    public function completedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'completed_by_actor_id');
    }

    public function cancelledByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'cancelled_by_actor_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    protected function casts(): array
    {
        return [
            'type' => EventTypeEnum::class,
            'status' => EventStatusEnum::class,
            'visibility' => EventVisibilityEnum::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'max_participants' => 'integer',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'participation_confirmation_version' => 'integer',
        ];
    }
}
