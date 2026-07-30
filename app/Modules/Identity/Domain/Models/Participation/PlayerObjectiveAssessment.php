<?php

namespace App\Modules\Identity\Domain\Models\Participation;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'stamina',
    'passing',
    'close_range_shooting',
    'mid_range_shooting',
    'long_range_shooting',
    'defense',
    'rebounding',
    'games_count',
    'confidence',
    'formula_version',
    'calculated_at',
])]
class PlayerObjectiveAssessment extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'stamina' => 'decimal:2',
            'passing' => 'decimal:2',
            'close_range_shooting' => 'decimal:2',
            'mid_range_shooting' => 'decimal:2',
            'long_range_shooting' => 'decimal:2',
            'defense' => 'decimal:2',
            'rebounding' => 'decimal:2',
            'games_count' => 'integer',
            'confidence' => 'decimal:4',
            'formula_version' => 'integer',
            'calculated_at' => 'immutable_datetime',
        ];
    }
}
