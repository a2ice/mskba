<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Template\Application\Services\TrustedTemplateRegistry;

final readonly class AcquisitionFlyerContentManager
{
    public function __construct(private TrustedTemplateRegistry $templates) {}

    /** @return array<string, array<string, mixed>> */
    public function fieldDefinitions(AcquisitionCampaign $campaign): array
    {
        return $this->templates->fieldDefinitions($this->templateKey($campaign));
    }

    /** @return array<string, string> */
    public function resolved(AcquisitionCampaign $campaign): array
    {
        $overrides = data_get($campaign->metadata, 'flyer_content', []);
        $overrides = is_array($overrides) ? $overrides : [];

        $resolved = [];
        foreach ($this->fieldDefinitions($campaign) as $key => $definition) {
            if (array_key_exists($key, $overrides)) {
                $resolved[$key] = (string) $overrides[$key];
                continue;
            }

            $resolved[$key] = $this->defaultValue($campaign, $definition);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $values */
    public function save(AcquisitionCampaign $campaign, array $values): void
    {
        $metadata = is_array($campaign->metadata) ? $campaign->metadata : [];
        $overrides = [];

        foreach ($this->fieldDefinitions($campaign) as $key => $definition) {
            $value = trim((string) ($values[$key] ?? ''));
            $default = $this->defaultValue($campaign, $definition);

            if ($value !== $default) {
                $overrides[$key] = $value;
            }
        }

        if ($overrides === []) {
            unset($metadata['flyer_content']);
        } else {
            $metadata['flyer_content'] = $overrides;
        }

        $campaign->forceFill(['metadata' => $metadata])->save();
    }

    public function reset(AcquisitionCampaign $campaign): void
    {
        $metadata = is_array($campaign->metadata) ? $campaign->metadata : [];
        unset($metadata['flyer_content']);

        $campaign->forceFill(['metadata' => $metadata])->save();
    }

    private function templateKey(AcquisitionCampaign $campaign): string
    {
        return (string) data_get($campaign->metadata, 'template_key', 'acquisition.flyer.a4');
    }

    /** @param array<string, mixed> $definition */
    private function defaultValue(AcquisitionCampaign $campaign, array $definition): string
    {
        $source = $definition['default_source'] ?? null;

        return match ($source) {
            'venue.name' => (string) ($campaign->venue?->name ?? ''),
            'campaign.placement' => trim((string) data_get($campaign->metadata, 'placement', '')),
            default => (string) ($definition['default'] ?? ''),
        };
    }
}
