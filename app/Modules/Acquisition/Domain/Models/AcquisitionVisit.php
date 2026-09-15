<?php

namespace App\Modules\Acquisition\Domain\Models;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionPersonaEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id',
    'user_id',
    'channel',
    'source',
    'medium',
    'campaign_name',
    'content',
    'term',
    'persona',
    'landing_path',
    'referrer',
    'location_status',
    'location_accuracy_m',
    'distance_to_venue_m',
    'location_verified_at',
    'visited_at',
    'linked_at',
])]
class AcquisitionVisit extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AcquisitionCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'channel' => AcquisitionChannelEnum::class,
            'persona' => AcquisitionPersonaEnum::class,
            'location_accuracy_m' => 'float',
            'distance_to_venue_m' => 'integer',
            'location_verified_at' => 'datetime',
            'visited_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }
}
