<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAccountPrivacySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'privacy' => ['required', 'array'],
            'privacy.*' => ['required', 'array'],
            'privacy.*.visibility' => ['required', Rule::enum(UserPrivacyVisibilityEnum::class)],
            'privacy.*.allowed_user_ids' => ['nullable', 'array', 'max:100'],
            'privacy.*.allowed_user_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('status', '!=', UserStatusEnum::BLOCKED->value)),
                Rule::notIn([(int) $this->user()?->getKey()]),
            ],
            'messenger_notifications' => ['required', Rule::enum(UserMessengerNotificationPreferenceEnum::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $privacy = $this->input('privacy', []);

                foreach (UserPrivacySettingTypeEnum::cases() as $type) {
                    $setting = $privacy[$type->value] ?? null;

                    if (! is_array($setting)) {
                        $validator->errors()->add(
                            "privacy.{$type->value}",
                            "Не указана настройка «{$type->label()}».",
                        );

                        continue;
                    }

                    if (
                        ($setting['visibility'] ?? null) === UserPrivacyVisibilityEnum::SELECTED_USERS->value
                        && empty($setting['allowed_user_ids'])
                    ) {
                        $validator->errors()->add(
                            "privacy.{$type->value}.allowed_user_ids",
                            'Выберите хотя бы одного пользователя.',
                        );
                    }

                    $allowedUserIds = $setting['allowed_user_ids'] ?? [];

                    if (is_array($allowedUserIds) && count($allowedUserIds) !== count(array_unique($allowedUserIds))) {
                        $validator->errors()->add(
                            "privacy.{$type->value}.allowed_user_ids",
                            'Один пользователь не должен быть выбран дважды.',
                        );
                    }
                }
            },
        ];
    }

    public function settings(): array
    {
        /** @var array<string, array{visibility: string, allowed_user_ids?: array<int, int>}> $settings */
        $settings = $this->validated('privacy');

        return $settings;
    }

    public function messengerNotifications(): UserMessengerNotificationPreferenceEnum
    {
        return UserMessengerNotificationPreferenceEnum::from(
            (string) $this->validated('messenger_notifications'),
        );
    }
}
