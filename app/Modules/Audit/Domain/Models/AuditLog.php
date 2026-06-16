<?php

namespace App\Modules\Audit\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'actor_id',
    'auditable_type',
    'auditable_id',
    'event',
    'old_values',
    'new_values',
    'metadata',
])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'auditable_id' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
