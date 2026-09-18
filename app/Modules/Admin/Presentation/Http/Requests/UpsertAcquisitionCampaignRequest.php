<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertAcquisitionCampaignRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'venue_id' => $this->filled('venue_id') ? $this->input('venue_id') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $campaign = $this->route('campaign');
        $campaignId = $campaign instanceof AcquisitionCampaign ? $campaign->id : null;

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
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'verification_radius_m' => ['required', 'integer', 'min:25', 'max:5000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
            'placement' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
