<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertAcquisitionCampaignRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'location_verification_enabled' => $this->boolean('location_verification_enabled'),
            'venue_id' => $this->filled('venue_id') ? $this->input('venue_id') : null,
            'landing_target_id' => $this->filled('landing_target_id') ? $this->input('landing_target_id') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $campaign = $this->route('campaign');
        $campaignId = $campaign instanceof AcquisitionCampaign ? $campaign->id : null;
        $channel = AcquisitionChannelEnum::tryFrom((string) $this->input('channel')) ?? AcquisitionChannelEnum::QR;
        $landingType = AcquisitionLandingTypeEnum::tryFrom((string) $this->input('landing_type'))
            ?? AcquisitionLandingTypeEnum::ONBOARDING;

        $landingTargetRules = [
            'nullable',
            'integer',
            Rule::requiredIf($landingType->needsTarget()),
        ];

        $landingTargetRule = match ($landingType) {
            AcquisitionLandingTypeEnum::VENUE => Rule::exists('venues', 'id')->where('status', 'confirmed'),
            AcquisitionLandingTypeEnum::EVENT => Rule::exists('events', 'id')
                ->where('visibility', 'public')
                ->whereIn('status', ['published', 'completed']),
            AcquisitionLandingTypeEnum::SPORTS_SECTION => Rule::exists('sports_sections', 'id')->where('status', 'active'),
            default => null,
        };

        if ($landingTargetRule !== null) {
            $landingTargetRules[] = $landingTargetRule;
        }

        return [
            'name' => ['required', 'string', 'max:160'],
            'public_code' => [
                'nullable',
                'string',
                'min:2',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('acquisition_campaigns', 'public_code')->ignore($campaignId),
            ],
            'channel' => ['required', 'string', Rule::enum(AcquisitionChannelEnum::class)],
            'landing_type' => ['required', 'string', Rule::enum(AcquisitionLandingTypeEnum::class)],
            'landing_target_id' => $landingTargetRules,
            'venue_id' => [
                'nullable',
                Rule::requiredIf($this->boolean('location_verification_enabled')),
                'integer',
                'exists:venues,id',
                Rule::prohibitedIf(! $channel->supportsPhysicalContext()),
            ],
            'location_verification_enabled' => [
                'required',
                'boolean',
                Rule::prohibitedIf(! $channel->supportsLocationVerification() && $this->boolean('location_verification_enabled')),
            ],
            'verification_radius_m' => [
                'nullable',
                Rule::requiredIf($this->boolean('location_verification_enabled')),
                'integer',
                'min:25',
                'max:5000',
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
            'placement' => ['nullable', 'string', 'max:160', Rule::prohibitedIf(! $channel->supportsPhysicalContext())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
