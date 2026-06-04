<?php

namespace App\Modules\Contact\Domain\Models;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ContactVerification::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
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
