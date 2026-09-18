<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use Illuminate\Support\Str;

final class AcquisitionCampaignManager
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?AcquisitionCampaign $campaign = null): AcquisitionCampaign
    {
        $campaign ??= new AcquisitionCampaign;
        $channel = AcquisitionChannelEnum::from((string) $data['channel']);
        $landingType = AcquisitionLandingTypeEnum::from((string) $data['landing_type']);

        $metadata = is_array($campaign->metadata) ? $campaign->metadata : [];

        $notes = trim((string) ($data['notes'] ?? ''));
        if ($notes === '') {
            unset($metadata['notes']);
        } else {
            $metadata['notes'] = $notes;
        }

        if ($channel->supportsPhysicalContext()) {
            $placement = trim((string) ($data['placement'] ?? ''));
            if ($placement === '') {
                unset($metadata['placement']);
            } else {
                $metadata['placement'] = $placement;
            }
        } else {
            unset($metadata['placement']);
        }

        $metadata['template_key'] = 'acquisition.flyer.a4';

        $locationVerificationEnabled = $channel->supportsLocationVerification()
            && (bool) ($data['location_verification_enabled'] ?? false);

        $campaign->fill([
            'public_code' => $this->publicCode($data['public_code'] ?? null, (string) $data['name'], $campaign),
            'name' => trim((string) $data['name']),
            'channel' => $channel,
            'landing_type' => $landingType,
            'landing_target_id' => $landingType->needsTarget() ? ($data['landing_target_id'] ?? null) : null,
            'venue_id' => $channel->supportsPhysicalContext() ? ($data['venue_id'] ?? null) : null,
            'verification_radius_m' => $locationVerificationEnabled
                ? (int) ($data['verification_radius_m'] ?? 250)
                : 250,
            'location_verification_enabled' => $locationVerificationEnabled,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'metadata' => $metadata,
        ]);
        $campaign->save();

        return $campaign->refresh();
    }

    private function publicCode(mixed $value, string $name, AcquisitionCampaign $campaign): string
    {
        $requested = trim((string) $value);
        if ($requested !== '') {
            return $requested;
        }

        if ($campaign->exists && is_string($campaign->public_code) && $campaign->public_code !== '') {
            return $campaign->public_code;
        }

        $base = Str::slug($name);
        $base = $base !== '' ? Str::limit($base, 50, '') : 'campaign';

        do {
            $candidate = $base.'-'.Str::lower(Str::random(6));
        } while (AcquisitionCampaign::query()->where('public_code', $candidate)->exists());

        return $candidate;
    }
}
