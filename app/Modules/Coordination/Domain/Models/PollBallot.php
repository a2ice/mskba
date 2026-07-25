<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['poll_id', 'user_id'])]
class PollBallot extends Model
{
    protected $table = 'coordination_ballots';

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(PollBallotSelection::class, 'ballot_id');
    }
}
