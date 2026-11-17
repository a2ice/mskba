<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'venue_integration_id', 'name', 'external_id', 'adapter_key', 'endpoint_url',
    'priority', 'capabilities', 'permission_status', 'permission_reference',
    'access_reviewed_at', 'credentials', 'enabled', 'version', 'updated_by_user_id',
])]
final class VenueIntegrationSource extends Model
{
    protected $hidden = ['credentials'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(VenueIntegration::class, 'venue_integration_id');
    }

    public function courtMappings(): HasMany
    {
        return $this->hasMany(VenueIntegrationCourtMapping::class);
    }

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'capabilities' => 'array',
            'access_reviewed_at' => 'immutable_datetime',
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
