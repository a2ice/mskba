<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdatePersonalDataDistributionConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['save', 'private'])],
            'public' => ['nullable', 'array'],
            'public.*' => ['nullable'],
            'distribution_consent' => ['nullable', 'accepted'],
        ];
    }

    /** @return list<UserPrivacySettingTypeEnum> */
    public function selectedTypes(): array
    {
        if ($this->validated('action') === 'private') {
            return [];
        }

        $public = $this->input('public', []);

        return collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->filter(fn (UserPrivacySettingTypeEnum $type): bool => is_array($public) && $this->boolean('public.'.$type->value))
            ->values()
            ->all();
    }

    protected function passedValidation(): void
    {
        if ($this->selectedTypes() !== [] && ! $this->boolean('distribution_consent')) {
            throw ValidationException::withMessages([
                'distribution_consent' => 'Подтвердите отдельное согласие на распространение выбранных персональных данных.',
            ]);
        }
    }
}
