<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id',
    'duplicate_venue_id',
    'matched_by',
    'status',
    'score',
    'metadata',
    'resolved_by',
    'resolved_at',
])]
class VenueDuplicate extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function duplicateVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'duplicate_venue_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'venue_id' => 'integer',
            'duplicate_venue_id' => 'integer',
            'matched_by' => VenueDuplicateMatchTypeEnum::class,
            'status' => VenueDuplicateStatusEnum::class,
            'score' => 'integer',
            'metadata' => 'array',
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }
}
