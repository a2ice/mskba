<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Domain\Models\Game;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tournament_id', 'entry_a_id', 'entry_b_id', 'game_id', 'round', 'sequence'])]
class TournamentMatch extends Model
{
    use Auditable, SoftDeletes;

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function entryA(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry_a_id');
    }

    public function entryB(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry_b_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    protected function casts(): array
    {
        return ['round' => 'integer', 'sequence' => 'integer'];
    }
}
