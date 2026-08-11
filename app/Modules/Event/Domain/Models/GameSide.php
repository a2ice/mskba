<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['event_id', 'game_id', 'team_id', 'slot', 'display_name', 'logo_preset', 'logo_disk', 'logo_path', 'score'])]
class GameSide extends Model
{
    public function logoUrl(): string
    {
        if ($this->team?->logo) {
            return $this->team->logo->publicUrl();
        }
        if ($this->logo_disk && $this->logo_path) {
            return $this->logo_disk === 'public' ? '/storage/'.ltrim($this->logo_path, '/') : Storage::disk($this->logo_disk)->url($this->logo_path);
        }

        return '/images/tournament-team-logos/'.($this->logo_preset ?: 'crest-00').'.webp';
    }

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
