<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Infrastructure\Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'created_by_actor_id',
    'title',
    'alias',
    'status',
    'status_comment',
    'starts_on',
    'ends_on',
    'short_description',
    'full_description',
    'format',
    'recruitment_mode',
])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static function newFactory(): TournamentFactory
    {
        return TournamentFactory::new();
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /** @param Builder<Tournament> $query */
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

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function staffMemberships(): HasMany
    {
        return $this->hasMany(ContractMembership::class, 'scope_id')
            ->where('scope_type', ContractMembershipScopeTypeEnum::TOURNAMENT->value);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(TournamentAdmission::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class)->orderBy('position')->orderBy('id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('sequence');
    }

    public function phase(): TournamentPhaseEnum
    {
        if ($this->status === TournamentStatusEnum::CANCELLED) {
            return TournamentPhaseEnum::CANCELLED;
        }

        if ($this->allMatchesCompleted() || $this->ends_on?->isBefore(today())) {
            return TournamentPhaseEnum::COMPLETED;
        }

        if ($this->competitionHasStarted() || $this->starts_on->lessThanOrEqualTo(today())) {
            return TournamentPhaseEnum::ONGOING;
        }

        return TournamentPhaseEnum::UPCOMING;
    }

    public function acceptsAdmissions(): bool
    {
        return $this->status === TournamentStatusEnum::CONFIRMED
            && $this->starts_on->greaterThanOrEqualTo(today())
            && ! $this->competitionHasStarted();
    }

    private function competitionHasStarted(): bool
    {
        return $this->matches()->whereHas('game', fn (Builder $query) => $query
            ->whereNotNull('actual_started_at')
            ->orWhereIn('status', [GameStatusEnum::IN_PROGRESS->value, GameStatusEnum::COMPLETED->value]))->exists();
    }

    private function allMatchesCompleted(): bool
    {
        return $this->matches()->exists()
            && ! $this->matches()->where(function (Builder $query): void {
                $query->whereNull('game_id')->orWhereHas('game', fn (Builder $gameQuery) => $gameQuery
                    ->where('status', '!=', GameStatusEnum::COMPLETED->value));
            })->exists();
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function cover(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', 'tournament_cover')
            ->where('is_featured', true);
    }

    protected function casts(): array
    {
        return [
            'status' => TournamentStatusEnum::class,
            'format' => GameFormatEnum::class,
            'recruitment_mode' => TournamentRecruitmentModeEnum::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
