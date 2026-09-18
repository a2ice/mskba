<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use Illuminate\Support\Str;

final class AcquisitionCampaignManager
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?AcquisitionCampaign $campaign = null): AcquisitionCampaign
    {
        $campaign ??= new AcquisitionCampaign;

        $metadata = is_array($campaign->metadata) ? $campaign->metadata : [];
        foreach (['placement', 'notes'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                unset($metadata[$key]);
            } else {
                $metadata[$key] = $value;
            }
        }
        $metadata['template_key'] = 'acquisition.flyer.a4';

        $campaign->fill([
            'public_code' => $this->publicCode($data['public_code'] ?? null, (string) $data['name'], $campaign),
            'name' => trim((string) $data['name']),
            'channel' => $data['channel'],
            'venue_id' => $data['venue_id'] ?? null,
            'verification_radius_m' => (int) ($data['verification_radius_m'] ?? 250),
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
