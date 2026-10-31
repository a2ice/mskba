<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['created_by_actor_id', 'name', 'slug', 'description', 'status'])]
class League extends Model
{
    use Auditable, SoftDeletes;

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(LeagueSeason::class);
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }
}
