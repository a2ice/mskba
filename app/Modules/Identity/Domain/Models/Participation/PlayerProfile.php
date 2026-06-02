<?php

namespace App\Modules\Identity\Domain\Models\Participation;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Infrastructure\Database\Factories\Participation\PlayerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'height_cm',
    'weight_kg',
    'position',
    'experience_started_year',
    'comment',
    'extra',
])]
class PlayerProfile extends Model
{
    /** @use HasFactory<PlayerProfileFactory> */
    use HasFactory;

    protected static function newFactory(): PlayerProfileFactory
    {
        return PlayerProfileFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function experienceYears(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->experience_started_year === null) {
                return null;
            }

            return max(0, now()->year - (int) $this->experience_started_year);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'height_cm' => 'integer',
            'weight_kg' => 'decimal:1',
            'position' => PlayerPositionEnum::class,
            'experience_started_year' => 'integer',
            'extra' => 'array',
        ];
    }
}
