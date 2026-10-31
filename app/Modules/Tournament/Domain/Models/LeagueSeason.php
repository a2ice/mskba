<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['league_id', 'created_by_actor_id', 'name', 'slug', 'description', 'status', 'starts_on', 'ends_on'])]
class LeagueSeason extends Model
{
    use Auditable, SoftDeletes;

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class, 'league_season_id');
    }

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }
}
