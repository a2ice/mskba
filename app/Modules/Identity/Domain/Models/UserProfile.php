<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'first_name',
    'last_name',
    'middle_name',
    'birth_date',
    'gender',
])]
class UserProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
