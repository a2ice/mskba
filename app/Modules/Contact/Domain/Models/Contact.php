<?php

namespace App\Modules\Contact\Domain\Models;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable([
    'contactable_type',
    'contactable_id',
    'type',
    'value',
    'label',
    'is_primary',
    'is_public',
    'verified_at',
    'meta',
])]
class Contact extends Model
{
    use SoftDeletes;

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ContactVerification::class);
    }

    public function hasBeenVerified(): bool
    {
        return $this->verified_at !== null;
    }

    protected function isVerified(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->hasBeenVerified(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactTypeEnum::class,
            'is_primary' => 'boolean',
            'is_public' => 'boolean',
            'verified_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
