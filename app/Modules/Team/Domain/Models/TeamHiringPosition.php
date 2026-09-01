<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamHiringStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'team_id',
    'status',
    'spots_total',
    'spots_filled',
    'positions',
    'minimum_experience_years',
    'gender',
    'description',
    'created_by_user_id',
    'closed_at',
])]
final class TeamHiringPosition extends Model
{
    use Auditable;

    /** @param Builder<TeamHiringPosition> $query */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', TeamHiringStatusEnum::ACTIVE)
            ->whereColumn('spots_filled', '<', 'spots_total');
    }

    public function remainingSpots(): int
    {
        return max(0, $this->spots_total - $this->spots_filled);
    }

    /** @return Collection<int, PlayerPositionEnum> */
    public function playerPositions(): Collection
    {
        return collect($this->positions ?? [])
            ->map(fn (string $position): PlayerPositionEnum => PlayerPositionEnum::from($position));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(TeamJoinRequest::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TeamHiringStatusEnum::class,
            'spots_total' => 'integer',
            'spots_filled' => 'integer',
            'positions' => 'array',
            'minimum_experience_years' => 'integer',
            'gender' => UserGenderEnum::class,
            'closed_at' => 'datetime',
        ];
    }
}
