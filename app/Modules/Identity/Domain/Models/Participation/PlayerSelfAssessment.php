<?php

namespace App\Modules\Identity\Domain\Models\Participation;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'player_profile_id',
    'stamina',
    'speed',
    'ball_handling',
    'passing',
    'close_range_shooting',
    'mid_range_shooting',
    'long_range_shooting',
    'defense',
    'rebounding',
    'basketball_iq',
])]
class PlayerSelfAssessment extends Model
{
    public const SKILLS = [
        'stamina' => 'Выносливость',
        'speed' => 'Скорость',
        'ball_handling' => 'Ведение мяча',
        'passing' => 'Передачи',
        'close_range_shooting' => 'Ближний бросок',
        'mid_range_shooting' => 'Средний бросок',
        'long_range_shooting' => 'Дальний бросок',
        'defense' => 'Защита',
        'rebounding' => 'Подбор',
        'basketball_iq' => 'Игровое мышление',
    ];

    public function playerProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamina' => 'integer',
            'speed' => 'integer',
            'ball_handling' => 'integer',
            'passing' => 'integer',
            'close_range_shooting' => 'integer',
            'mid_range_shooting' => 'integer',
            'long_range_shooting' => 'integer',
            'defense' => 'integer',
            'rebounding' => 'integer',
            'basketball_iq' => 'integer',
        ];
    }
}
