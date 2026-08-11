<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['tournament_id', 'admission_id', 'source', 'team_id', 'name', 'logo_preset', 'status', 'seed', 'position', 'locked_at'])]
class TournamentEntry extends Model
{
    use Auditable;

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(TournamentAdmission::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TournamentEntryMember::class)->orderBy('position');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection', 'tournament_entry_logo')->where('is_featured', true);
    }

    public function logoUrl(): string
    {
        return $this->team?->logo?->publicUrl()
            ?? $this->logo?->publicUrl()
            ?? '/images/tournament-team-logos/'.($this->logo_preset ?: 'crest-00').'.webp';
    }

    public function matchesAsA(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'entry_a_id');
    }

    public function matchesAsB(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'entry_b_id');
    }

    protected function casts(): array
    {
        return ['source' => TournamentEntrySourceEnum::class, 'status' => TournamentEntryStatusEnum::class, 'seed' => 'integer', 'position' => 'integer', 'locked_at' => 'immutable_datetime'];
    }
}
