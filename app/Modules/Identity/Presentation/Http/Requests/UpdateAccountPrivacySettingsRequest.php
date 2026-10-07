<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
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
            'messenger_notifications' => ['nullable', Rule::enum(UserMessengerNotificationPreferenceEnum::class)],
            'email_notifications' => ['nullable', Rule::enum(UserMessengerNotificationPreferenceEnum::class)],
            'distribution_consent' => ['nullable', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $privacy = $this->input('privacy', []);

                $user = $this->user()?->canonical();
                $distributionConsents = app(PersonalDataDistributionConsentService::class);

                foreach (UserPrivacySettingTypeEnum::cases() as $type) {
                    if (! array_key_exists($type->value, $privacy) && ! in_array($type, [UserPrivacySettingTypeEnum::DISCOVERABILITY, UserPrivacySettingTypeEnum::CONTACTS, UserPrivacySettingTypeEnum::MESSAGES, UserPrivacySettingTypeEnum::GROUP_INVITATIONS], true)) {
                        continue;
                    }
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

                if ($user !== null && $distributionConsents->isEnforcedFor($user)) {
                    $existingSettings = $user->privacySettings()
                        ->get()
                        ->keyBy(fn ($setting): string => $setting->type->value);

                    $publicTypeValues = collect(UserPrivacySettingTypeEnum::distributionTypes())
                        ->filter(function (UserPrivacySettingTypeEnum $type) use ($privacy, $existingSettings): bool {
                            $submitted = $privacy[$type->value] ?? null;
                            $visibility = is_array($submitted)
                                ? ($submitted['visibility'] ?? null)
                                : ($existingSettings->get($type->value)?->visibility->value ?? $type->defaultVisibility()->value);

                            return $visibility === UserPrivacyVisibilityEnum::EVERYONE->value;
                        })
                        ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
                        ->values()
                        ->all();

                    if (
                        $distributionConsents->needsAcceptance($user, $publicTypeValues)
                        && ! $this->boolean('distribution_consent')
                    ) {
                        $validator->errors()->add(
                            'distribution_consent',
                            'Подтвердите отдельное согласие на публичное распространение выбранных персональных данных.',
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

    public function distributionConsentAccepted(): bool
    {
        return $this->boolean('distribution_consent');
    }

    public function messengerNotifications(): UserMessengerNotificationPreferenceEnum
    {
        $value = $this->validated('messenger_notifications');

        return is_string($value)
            ? UserMessengerNotificationPreferenceEnum::from($value)
            : UserMessengerNotificationPreferenceEnum::ALL;
    }

    public function emailNotifications(): UserMessengerNotificationPreferenceEnum
    {
        $value = $this->validated('email_notifications');

        return is_string($value)
            ? UserMessengerNotificationPreferenceEnum::from($value)
            : UserMessengerNotificationPreferenceEnum::ALL;
    }
}
