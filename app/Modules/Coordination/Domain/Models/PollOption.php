<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'poll_id',
    'label',
    'value',
    'sort_order',
    'is_active',
    'proposed_by_user_id',
])]
class PollOption extends Model
{
    protected $table = 'coordination_poll_options';

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(PollBallotSelection::class, 'option_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
