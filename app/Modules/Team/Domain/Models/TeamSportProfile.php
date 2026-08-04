<?php

namespace App\Modules\Team\Domain\Models;

use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'sport_type'])]
class TeamSportProfile extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return ['sport_type' => TeamSportTypeEnum::class];
    }
}
