<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Application\Services\EventContractResponsibilityService;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Infrastructure\Database\Factories\EventFactory;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBooking as RentalVenueBooking;
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
    'venue_court_id',
    'booking_id',
    'booking_snapshot',
    'organizer_actor_id',
    'primary_game_id',
    'title',
    'alias',
    'type',
    'status',
    'visibility',
    'description',
    'result_description',
    'discussion_enabled',
    'report_comments_enabled',
    'starts_at',
    'ends_at',
    'max_participants',
    'is_paid',
    'total_cost_minor',
    'participant_cost_minor',
    'participant_cost_auto',
    'cost_comment',
    'cost_inclusions',
    'time_comment',
    'time_confirmed',
    'completed_at',
    'completed_by_actor_id',
    'cancelled_at',
    'cancelled_by_actor_id',
    'cancellation_reason',
    'participation_confirmation_version',
    'planned_booking_scope',
    'booking_deferred',
    'proposed_time_options',
    'creation_key',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $hidden = ['cost_inclusions'];

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    protected static function booted(): void
    {
        static::created(function (self $event): void {
            app(EventContractResponsibilityService::class)->ensureOwner($event);
        });

        static::saving(function (self $event): void {
            // Event-first preferences explicitly permit any hall. Do not turn
            // a nullable preference or an explicitly selected hall into the primary one.
            if ($event->venue_id === null || $event->proposed_time_options !== null) {
                return;
            }

            if ($event->venue_court_id !== null && ! ($event->isDirty('venue_id') && ! $event->isDirty('venue_court_id'))) {
                return;
            }

            $courtId = VenueCourt::query()
                ->where('venue_id', $event->venue_id)
                ->where('is_primary', true)
                ->value('id')
                ?? VenueCourt::query()->where('venue_id', $event->venue_id)->orderBy('sort_order')->orderBy('id')->value('id');

            if ($courtId !== null) {
                $event->venue_court_id = (int) $courtId;
            }
        });
    }

    public function canManageFeedback(?\App\Modules\Identity\Domain\Models\User $user): bool
    {
        $user = $user?->canonical();
        if (! $user || $user->trashed() || $user->isBlocked()) {
            return false;
        }

        return $this->organizerActor()->whereIn('user_id', $user->identityIds())->exists()
            || ($user->isConfirmed() && $user->system_role === \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN);
    }

    public function hasEnded(): bool
    {
        return $this->status === EventStatusEnum::COMPLETED
            || ($this->status === EventStatusEnum::PUBLISHED && $this->ends_at !== null && $this->ends_at->isPast());
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /** @param Builder<Event> $query */
    public function scopeWhereRouteIdentifier(Builder $query, string $identifier): Builder
    {
        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier);
        }

        if (preg_match('/^(\d+)-/', $identifier, $matches) === 1) {
            return $query->whereKey((int) $matches[1]);
        }

        return $query->where('alias', $identifier);
    }

    /** @param Builder<Event> $query @param list<int> $identities */
    public function scopeForActiveParticipation(Builder $query, array $identities): Builder
    {
        return $query->where(function (Builder $q) use ($identities): void {
            $q->whereHas('participants', fn (Builder $p) => $p
                ->whereIn('user_id', $identities)->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->whereColumn('confirmation_version', 'events.participation_confirmation_version'))
                ->orWhereHas('primaryGame.admissions', fn (Builder $a) => $a
                    ->whereIn('user_id', $identities)->where('status', GameAdmissionStatusEnum::ACCEPTED->value))
                ->orWhereHas('organizerActor', fn (Builder $o) => $o->whereIn('user_id', $identities));
        });
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    public function organizerActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'organizer_actor_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function trainingSession(): HasOne
    {
        return $this->hasOne(TrainingSession::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(VenueBooking::class);
    }

    public function venueInterests(): HasMany
    {
        return $this->hasMany(EventVenueInterest::class);
    }

    public function venueRevisions(): HasMany
    {
        return $this->hasMany(EventVenueRevision::class)->orderBy('revision');
    }

    public function sourceBooking(): BelongsTo
    {
        return $this->belongsTo(RentalVenueBooking::class, 'booking_id');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function primaryGame(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'primary_game_id');
    }

    public function telegramPublications(): HasMany
    {
        return $this->hasMany(TelegramEventPublication::class);
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
            'venue_court_id' => 'integer',
            'type' => EventTypeEnum::class,
            'status' => EventStatusEnum::class,
            'visibility' => EventVisibilityEnum::class,
            'booking_snapshot' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'max_participants' => 'integer',
            'is_paid' => 'boolean',
            'total_cost_minor' => 'integer',
            'participant_cost_minor' => 'integer',
            'participant_cost_auto' => 'boolean',
            'cost_inclusions' => 'array',
            'time_confirmed' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'participation_confirmation_version' => 'integer',
            'planned_booking_scope' => \App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::class,
            'booking_deferred' => 'boolean',
            'discussion_enabled' => 'boolean',
            'report_comments_enabled' => 'boolean',
            'proposed_time_options' => 'array',
        ];
    }
}
