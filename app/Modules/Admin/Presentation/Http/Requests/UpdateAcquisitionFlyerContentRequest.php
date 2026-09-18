<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Acquisition\Application\Services\AcquisitionFlyerContentManager;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAcquisitionFlyerContentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $campaign = $this->route('campaign');

        if (! $campaign instanceof AcquisitionCampaign) {
            return [
                'content' => ['required', 'array'],
                'reset' => ['nullable', 'boolean'],
            ];
        }

        $definitions = app(AcquisitionFlyerContentManager::class)->fieldDefinitions($campaign);

        $rules = [
            'content' => ['required_unless:reset,1', 'array'],
            'reset' => ['nullable', 'boolean'],
        ];

        foreach ($definitions as $key => $definition) {
            $rules['content.'.$key] = [
                'nullable',
                'string',
                'max:'.(int) ($definition['max'] ?? 255),
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reset' => $this->boolean('reset'),
        ]);
    }
}
