<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'temporary_for_event_id',
    'created_by_actor_id',
    'name',
    'base_name',
    'normalized_name',
    'name_sequence',
    'alias',
    'description',
    'status',
    'accepts_join_requests',
])]
class Team extends Model
{
    use Auditable, SoftDeletes;

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /** @param Builder<Team> $query */
    public function scopeWhereRouteIdentifier(Builder $query, string $identifier): Builder
    {
        if (preg_match('/^(\d+)-/', $identifier, $matches) === 1) {
            return $query->whereKey((int) $matches[1]);
        }

        return $query->where('alias', $identifier);
    }

    public function temporaryForEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'temporary_for_event_id');
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ContractMembership::class, 'scope_id')
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(TeamJoinRequest::class);
    }

    public function sportProfiles(): HasMany
    {
        return $this->hasMany(TeamSportProfile::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', 'team_logo')
            ->where('is_featured', true);
    }

    public function isTemporary(): bool
    {
        return $this->temporary_for_event_id !== null;
    }

    protected function casts(): array
    {
        return [
            'status' => TeamStatusEnum::class,
            'name_sequence' => 'integer',
            'accepts_join_requests' => 'boolean',
        ];
    }
}
