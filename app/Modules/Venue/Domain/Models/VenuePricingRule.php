<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'venue_id', 'venue_court_id', 'rule_key', 'version', 'status',
    'is_enabled', 'name', 'description', 'priority', 'stop_processing',
    'conditions', 'operation', 'value', 'funding_source',
    'published_by_user_id', 'published_at',
])]
final class VenuePricingRule extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->getOriginal('status') !== 'draft') {
                $changes = array_keys($rule->getDirty());
                if ($changes !== ['status'] || $rule->status !== 'archived') {
                    throw new LogicException('Опубликованное тарифное правило нельзя изменять. Создайте новую редакцию.');
                }
            }
        });
        static::deleting(static function (self $rule): void {
            if ($rule->status !== 'draft') {
                throw new LogicException('Опубликованные и архивные правила нельзя удалять.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'stop_processing' => 'boolean',
            'conditions' => 'array',
            'value' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }
}
