<?php

namespace App\Modules\Rewards\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'description',
    'mechanism_code',
    'is_enabled',
])]
class Reward extends Model
{
    use Auditable, SoftDeletes;

    public function versions(): HasMany
    {
        return $this->hasMany(RewardVersion::class)
            ->orderByDesc('version_number')
            ->orderByDesc('id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(RewardVersion::class)
            ->whereNull('valid_until')
            ->latestOfMany('id');
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
