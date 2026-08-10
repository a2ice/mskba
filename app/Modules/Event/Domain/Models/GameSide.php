<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_id', 'game_id', 'team_id', 'slot', 'display_name', 'score'])]
class GameSide extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function rosterEntries(): HasMany
    {
        return $this->hasMany(GameRosterEntry::class);
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(GamePlayerStatistic::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }
}
