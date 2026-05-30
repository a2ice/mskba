<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Infrastructure\Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['created_by_user_id', 'name', 'alias', 'type', 'status', 'description'])]
#[Hidden([])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }

    public function venueContracts(): HasMany
    {
        return $this->hasMany(VenueContract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VenueTypeEnum::class,
            'status' => VenueStatusEnum::class,
        ];
    }
}
