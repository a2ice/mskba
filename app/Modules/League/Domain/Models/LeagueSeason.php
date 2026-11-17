<?php

namespace App\Modules\League\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Media\Domain\Traits\HasEntityBanner;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['league_id', 'name', 'description', 'starts_on', 'ends_on', 'status'])]
class LeagueSeason extends Model
{
    use Auditable, HasEntityBanner, SoftDeletes;

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'league_season_team')->withTimestamps();
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
