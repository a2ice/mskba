<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Infrastructure\Database\Factories\ProfileFactory;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

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

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function activeAvatar(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', 'avatar')
            ->where('is_featured', true);
    }

    public function avatarUrl(): ?string
    {
        $avatar = $this->relationLoaded('activeAvatar')
            ? $this->activeAvatar
            : $this->activeAvatar()->first();

        return $avatar?->publicUrl();
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
