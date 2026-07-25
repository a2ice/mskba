<?php

namespace App\Modules\Coordination\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ballot_id', 'option_id'])]
class PollBallotSelection extends Model
{
    protected $table = 'coordination_ballot_selections';

    public function ballot(): BelongsTo
    {
        return $this->belongsTo(PollBallot::class, 'ballot_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
