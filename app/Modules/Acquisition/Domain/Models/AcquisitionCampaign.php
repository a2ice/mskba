<?php

namespace App\Modules\Acquisition\Domain\Models;

use App\Modules\Acquisition\Domain\Enums\AcquisitionCampaignStateEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_code',
    'name',
    'channel',
    'landing_type',
    'landing_target_id',
    'venue_id',
    'verification_radius_m',
    'location_verification_enabled',
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

    public function state(): AcquisitionCampaignStateEnum
    {
        if (! $this->is_active) {
            return AcquisitionCampaignStateEnum::INACTIVE;
        }

        $now = now();

        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return AcquisitionCampaignStateEnum::SCHEDULED;
        }

        if ($this->ends_at !== null && $this->ends_at->lt($now)) {
            return AcquisitionCampaignStateEnum::ENDED;
        }

        return AcquisitionCampaignStateEnum::ACTIVE;
    }

    public function isAvailable(): bool
    {
        return $this->state() === AcquisitionCampaignStateEnum::ACTIVE;
    }

    protected function casts(): array
    {
        return [
            'channel' => AcquisitionChannelEnum::class,
            'landing_type' => AcquisitionLandingTypeEnum::class,
            'landing_target_id' => 'integer',
            'verification_radius_m' => 'integer',
            'location_verification_enabled' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
