<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only service audit. No model fillable or administrative update/delete
 * actions; write through the integration manager with a non-secret allowlist.
 */
final class VenueIntegrationAuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(VenueIntegration::class, 'venue_integration_id');
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
