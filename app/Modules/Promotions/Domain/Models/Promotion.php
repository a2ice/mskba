<?php

namespace App\Modules\Promotions\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Rewards\Domain\Models\Reward;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'type', 'reward_id', 'title', 'description', 'cta_label', 'cta_url', 'is_enabled', 'starts_at', 'ends_at'])]
final class Promotion extends Model
{
    use Auditable, SoftDeletes;

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(PromotionPlacement::class);
    }

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reward_id' => 'integer',
        ];
    }
}
