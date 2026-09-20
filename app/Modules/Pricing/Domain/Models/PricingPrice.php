<?php

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_id',
    'variant_id',
    'amount_minor',
    'currency',
    'valid_from',
    'valid_until',
    'is_active',
])]
class PricingPrice extends Model
{
    use Auditable, SoftDeletes;

    public function service(): BelongsTo
    {
        return $this->belongsTo(PricingService::class, 'service_id')->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PricingVariant::class, 'variant_id')->withTrashed();
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where('valid_from', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', $now);
            });
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount_minor / 100, 2, ',', ' ').' '.$this->currency;
    }

    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'variant_id' => 'integer',
            'amount_minor' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
