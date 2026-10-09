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
            'privacy_hierarchy' => ['nullable', Rule::in(['1', 1])],
            'privacy_options' => ['nullable', 'array:discoverability,messages,group_invitations'],
            'privacy_options.*' => ['required', Rule::in(['everyone', 'nobody'])],
            'distribution_consent' => [
                'exclude_if:action,private',
                Rule::excludeIf(fn (): bool => ! $this->hasSelectedPublicTypes()),
                'nullable',
                'accepted',
            ],
        ];
    }

    /**
     * Do not require a distribution consent for an entirely private profile.
     * Uses the raw request so validation rules cannot recurse into validated().
     */
    private function hasSelectedPublicTypes(): bool
    {
        if (! is_array($this->input('public', []))) {
            return false;
        }

        return $this->selectedTypes() !== [];
    }

    /** @return list<UserPrivacySettingTypeEnum> */
    public function selectedTypes(): array
    {
        if ($this->input('action') === 'private') {
            return [];
        }

        $public = $this->input('public', []);

        $hierarchy = $this->boolean('privacy_hierarchy');
        $profileOpen = $this->boolean('public.profile');

        return collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->filter(function (UserPrivacySettingTypeEnum $type) use ($public, $hierarchy, $profileOpen): bool {
                if (! is_array($public) || ! $this->boolean('public.'.$type->value)) {
                    return false;
                }

                // Root controls are authoritative even if the browser posts hidden children.
                if ($hierarchy && ! $profileOpen && $type !== UserPrivacySettingTypeEnum::PROFILE) {
                    return false;
                }

                // Player details depend on opening the player page first.
                if ($hierarchy && str_starts_with($type->value, 'player_')
                    && ! $this->boolean('public.role_player')) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function privacyOptions(): array
    {
        if ($this->input('action') === 'private') {
            return [
                'discoverability' => 'nobody',
                'messages' => 'nobody',
                'group_invitations' => 'nobody',
            ];
        }

        $options = $this->validated('privacy_options', []);
        if (! is_array($options)) {
            return [];
        }

        // The profile is the parent of discoverability; disabled search cannot
        // leave group invitations or conversations enabled via a forged POST.
        if (
            ($this->boolean('privacy_hierarchy') && ! $this->boolean('public.profile'))
            || ($options['discoverability'] ?? 'nobody') !== 'everyone'
        ) {
            return [
                'discoverability' => 'nobody',
                'messages' => 'nobody',
                'group_invitations' => 'nobody',
            ];
        }

        return [
            'discoverability' => 'everyone',
            'messages' => $options['messages'] ?? 'nobody',
            'group_invitations' => $options['group_invitations'] ?? 'nobody',
        ];
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
