<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateUserPrivacySettingsHandler
{
    public function __construct(
        private readonly PersonalDataDistributionConsentService $distributionConsents,
    ) {}

    /**
     * @param  array<string, array{visibility: string, allowed_user_ids?: array<int, int>}>  $settings
     */
    public function handle(
        User $user,
        array $settings,
        UserMessengerNotificationPreferenceEnum $messengerNotifications,
        UserMessengerNotificationPreferenceEnum $emailNotifications,
        ?PrivacyConsentDTO $distributionConsentEvidence = null,
    ): void {
        $user = $user->canonical();

        DB::transaction(function () use (
            $user,
            $settings,
            $messengerNotifications,
            $emailNotifications,
            $distributionConsentEvidence,
        ): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach (UserPrivacySettingTypeEnum::cases() as $type) {
                if (! array_key_exists($type->value, $settings)) {
                    continue;
                }

                $data = $settings[$type->value];
                $visibility = UserPrivacyVisibilityEnum::from($data['visibility']);

                $setting = UserPrivacySetting::query()->updateOrCreate(
                    ['user_id' => $lockedUser->getKey(), 'type' => $type->value],
                    ['visibility' => $visibility->value],
                );

                $allowedUserIds = $visibility === UserPrivacyVisibilityEnum::SELECTED_USERS
                    ? array_values(array_unique($data['allowed_user_ids'] ?? []))
                    : [];

                $setting->allowedUsers()->sync($allowedUserIds);
            }

            if ($lockedUser->personal_data_distribution_required_at !== null) {
                $this->syncDistributionConsent(
                    $lockedUser,
                    $distributionConsentEvidence,
                );
            }

            UserNotificationSetting::query()->updateOrCreate(
                ['user_id' => $lockedUser->getKey()],
                [
                    'messenger_notifications' => $messengerNotifications->value,
                    'email_notifications' => $emailNotifications->value,
                ],
            );
        }, 3);

        $this->distributionConsents->forget($user);
    }

    private function syncDistributionConsent(
        User $user,
        ?PrivacyConsentDTO $evidence,
    ): void {
        $settings = UserPrivacySetting::query()
            ->where('user_id', $user->getKey())
            ->whereIn(
                'type',
                collect(UserPrivacySettingTypeEnum::distributionTypes())
                    ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
                    ->all(),
            )
            ->get()
            ->keyBy(fn (UserPrivacySetting $setting): string => $setting->type->value);

        $selectedValues = collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->filter(function (UserPrivacySettingTypeEnum $type) use ($settings): bool {
                $visibility = $settings->get($type->value)?->visibility ?? $type->defaultVisibility();

                return $visibility === UserPrivacyVisibilityEnum::EVERYONE;
            })
            ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->values()
            ->all();

        $activeConsent = $user->consents()
            ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
            ->whereNull('revoked_at')
            ->latest('accepted_at')
            ->latest('id')
            ->first();

        $currentAllowed = is_array($activeConsent?->payload)
            ? ($activeConsent->payload['allowed_types'] ?? [])
            : [];
        $currentAllowed = $this->normalizeDistributionTypes(
            is_array($currentAllowed) ? $currentAllowed : [],
        );

        $requiresReplacement = $currentAllowed !== $selectedValues
            || (
                $selectedValues !== []
                && $activeConsent?->document_version !== (string) config('legal.personal_data_distribution_consent_version')
            );

        if ($requiresReplacement) {
            if ($selectedValues !== [] && $evidence === null) {
                throw new InvalidArgumentException(
                    'Для публичного распространения данных требуется отдельное согласие.',
                );
            }

            $now = now();

            $user->consents()
                ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($selectedValues !== [] && $evidence !== null) {
                $profile = $user->profile()->first();
                $verifiedContact = $user->identityContactsQuery()
                    ->whereNotNull('verified_at')
                    ->orderByDesc('is_primary')
                    ->orderByDesc('verified_at')
                    ->first();

                $user->consents()->create([
                    'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
                    'document_version' => $evidence->documentVersion,
                    'accepted_at' => $evidence->acceptedAt,
                    'source' => $evidence->source,
                    'ip_address' => $evidence->ipAddress,
                    'user_agent' => $evidence->userAgent,
                    'payload' => [
                        'allowed_types' => $selectedValues,
                        'portal_url' => url('/'),
                        'subject' => [
                            'username' => $user->username,
                            'first_name' => $profile?->first_name,
                            'last_name' => $profile?->last_name,
                            'middle_name' => $profile?->middle_name,
                            'verified_contact' => $verifiedContact?->displayValue(),
                        ],
                    ],
                ]);
            }
        }

        if ($user->personal_data_distribution_setup_completed_at === null) {
            $user->forceFill([
                'personal_data_distribution_setup_completed_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeDistributionTypes(array $values): array
    {
        return collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->filter(fn (UserPrivacySettingTypeEnum $type): bool => in_array($type->value, $values, true))
            ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->values()
            ->all();
    }
}
