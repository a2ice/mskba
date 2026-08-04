<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'sport_type'])]
class TeamSportProfile extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function lineupMembers(): HasMany
    {
        return $this->hasMany(TeamSportLineupMember::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['sport_type' => TeamSportTypeEnum::class];
    }
}
