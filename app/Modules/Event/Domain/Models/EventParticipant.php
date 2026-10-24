<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'event_id',
    'user_id',
    'role',
    'participation_roles',
    'status',
    'joined_at',
    'left_at',
    'confirmation_version',
    'responsibility_status',
    'responsibility_requested_by_user_id',
    'responsibility_requested_at',
    'responsibility_responded_at',
    'status_changed_by_actor_id',
    'status_changed_at',
])]
class EventParticipant extends Model
{
    /**
     * The player-seat count is independent from organizer privileges.
     * Legacy regular participants without activity metadata are players;
     * legacy organizers without an explicit player role are not assumed to play.
     *
     * @param Builder<EventParticipant> $query
     */
    public function scopeOccupiesPlayerSpot(Builder $query): Builder
    {
        return $query
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->where(function (Builder $roles): void {
                $roles->whereJsonContains('participation_roles', 'player')
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('participation_roles')
                            ->where('role', EventParticipantRoleEnum::PARTICIPANT->value);
                    });
            });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responsibilityPermissions(): HasMany
    {
        return $this->hasMany(EventResponsibilityPermission::class);
    }

    public function statusChangedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'status_changed_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'role' => EventParticipantRoleEnum::class,
            'participation_roles' => 'array',
            'status' => EventParticipantStatusEnum::class,
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'confirmation_version' => 'integer',
            'responsibility_status' => EventResponsibilityStatusEnum::class,
            'responsibility_requested_by_user_id' => 'integer',
            'responsibility_requested_at' => 'immutable_datetime',
            'responsibility_responded_at' => 'immutable_datetime',
            'status_changed_by_actor_id' => 'integer',
            'status_changed_at' => 'immutable_datetime',
        ];
    }
}
