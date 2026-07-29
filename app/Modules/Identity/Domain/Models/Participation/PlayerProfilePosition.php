<?php

namespace App\Modules\Identity\Domain\Models\Participation;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['player_profile_id', 'position'])]
class PlayerProfilePosition extends Model
{
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
            'position' => PlayerPositionEnum::class,
        ];
    }
}
