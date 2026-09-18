<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdatePersonalDataDistributionConsentHandler
{
    public function __construct(
        private readonly PersonalDataDistributionConsentService $consents,
    ) {}

    /**
     * @param list<UserPrivacySettingTypeEnum> $selectedTypes
     */
    public function handle(
        User $user,
        array $selectedTypes,
        ?PrivacyConsentDTO $evidence,
    ): void {
        $selectedTypes = collect($selectedTypes)
            ->filter(fn (mixed $type): bool => $type instanceof UserPrivacySettingTypeEnum && $type->requiresDistributionConsent())
            ->unique(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->values()
            ->all();

        if ($selectedTypes !== [] && $evidence === null) {
            throw new InvalidArgumentException('Для публичного распространения данных требуется отдельное согласие.');
        }

        $selectedValues = collect($selectedTypes)
            ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->all();

        DB::transaction(function () use ($user, $selectedValues, $evidence): void {
            $lockedUser = User::query()
                ->whereKey($user->canonical()->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();

            $lockedUser->consents()
                ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach (UserPrivacySettingTypeEnum::distributionTypes() as $type) {
                $setting = UserPrivacySetting::query()->updateOrCreate(
                    [
                        'user_id' => $lockedUser->getKey(),
                        'type' => $type->value,
                    ],
                    [
                        'visibility' => in_array($type->value, $selectedValues, true)
                            ? UserPrivacyVisibilityEnum::EVERYONE->value
                            : UserPrivacyVisibilityEnum::NOBODY->value,
                    ],
                );

                $setting->allowedUsers()->sync([]);
            }

            if ($selectedValues !== [] && $evidence !== null) {
                $profile = $lockedUser->profile()->first();
                $verifiedContact = $lockedUser->identityContactsQuery()
                    ->whereNotNull('verified_at')
                    ->orderByDesc('is_primary')
                    ->orderByDesc('verified_at')
                    ->first();

                $lockedUser->consents()->create([
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
                            'username' => $lockedUser->username,
                            'first_name' => $profile?->first_name,
                            'last_name' => $profile?->last_name,
                            'middle_name' => $profile?->middle_name,
                            'verified_contact' => $verifiedContact?->displayValue(),
                        ],
                    ],
                ]);
            }

            $lockedUser->forceFill([
                'personal_data_distribution_setup_completed_at' => $now,
            ])->save();
        }, 3);

        $this->consents->forget($user);
    }
}
