<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Infrastructure\Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable([
    'user_id',
    'first_name',
    'last_name',
    'middle_name',
    'gender',
    'birth_date',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): ProfileFactory
    {
        return ProfileFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function age(): Attribute
    {
        return Attribute::get(
            fn () => $this->birth_date?->age
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => UserGenderEnum::class,
        ];
    }
}
