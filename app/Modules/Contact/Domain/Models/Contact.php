<?php

namespace App\Modules\Contact\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use Auditable, SoftDeletes;

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

    public function displayValue(): string
    {
        if ($this->type !== ContactTypeEnum::TELEGRAM || ($this->meta['source'] ?? null) !== 'telegram_mini_app') {
            return $this->value;
        }

        if (filled($this->meta['username'] ?? null)) {
            return '@'.ltrim((string) $this->meta['username'], '@');
        }

        $name = trim(implode(' ', array_filter([
            $this->meta['first_name'] ?? null,
            $this->meta['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : 'Telegram ID '.$this->value;
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
