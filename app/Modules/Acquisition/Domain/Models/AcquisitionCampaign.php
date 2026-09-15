<?php

namespace App\Modules\Acquisition\Domain\Models;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_code',
    'name',
    'channel',
    'venue_id',
    'verification_radius_m',
    'is_active',
    'starts_at',
    'ends_at',
    'metadata',
])]
class AcquisitionCampaign extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(AcquisitionVisit::class, 'campaign_id');
    }

    public function isAvailable(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }

    protected function casts(): array
    {
        return [
            'channel' => AcquisitionChannelEnum::class,
            'verification_radius_m' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
