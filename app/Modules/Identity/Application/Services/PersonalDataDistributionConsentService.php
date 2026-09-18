<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;

final class PersonalDataDistributionConsentService
{
    /** @var array<int, list<string>> */
    private array $allowedTypesCache = [];

    public function requiresSetup(User $user): bool
    {
        $user = $user->canonical();

        return $user->personal_data_distribution_required_at !== null
            && $user->personal_data_distribution_setup_completed_at === null;
    }

    public function isEnforcedFor(User $user): bool
    {
        return $user->canonical()->personal_data_distribution_required_at !== null;
    }

    public function allows(User $user, UserPrivacySettingTypeEnum $type): bool
    {
        if (! $type->requiresDistributionConsent()) {
            return true;
        }

        $user = $user->canonical();

        if (! $this->isEnforcedFor($user)) {
            // Legacy accounts stay on their historical privacy rules until a migration pass.
            return true;
        }

        return in_array($type->value, $this->allowedTypeValues($user), true);
    }

    /** @return list<string> */
    public function allowedTypeValues(User $user): array
    {
        $user = $user->canonical();

        if (array_key_exists((int) $user->id, $this->allowedTypesCache)) {
            return $this->allowedTypesCache[(int) $user->id];
        }

        $consent = $user->consents()
            ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
            ->whereNull('revoked_at')
            ->latest('accepted_at')
            ->latest('id')
            ->first();

        $allowed = is_array($consent?->payload)
            ? ($consent->payload['allowed_types'] ?? [])
            : [];

        $validValues = collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->all();

        return $this->allowedTypesCache[(int) $user->id] = collect(is_array($allowed) ? $allowed : [])
            ->filter(fn (mixed $value): bool => is_string($value) && in_array($value, $validValues, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function selectedForForm(User $user): array
    {
        $user = $user->canonical();

        if ($user->personal_data_distribution_setup_completed_at !== null) {
            return $this->allowedTypeValues($user);
        }

        $selected = [
            UserPrivacySettingTypeEnum::PROFILE->value,
            UserPrivacySettingTypeEnum::AVATAR->value,
        ];

        if ($user->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_PLAYER->value,
                UserPrivacySettingTypeEnum::PLAYER_CHARACTERISTICS->value,
                UserPrivacySettingTypeEnum::PLAYER_TEAMS->value,
                UserPrivacySettingTypeEnum::PLAYER_GAMES->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::COACH->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_COACH->value,
                UserPrivacySettingTypeEnum::COACH_SECTIONS->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::REFEREE->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_REFEREE->value,
                UserPrivacySettingTypeEnum::REFEREE_EVENTS->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::STATISTICIAN->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_STATISTICIAN->value,
                UserPrivacySettingTypeEnum::STATISTICIAN_EVENTS->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::MEDIA->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_MEDIA->value,
                UserPrivacySettingTypeEnum::MEDIA_MATERIALS->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::VENUE_RELATED->value)) {
            array_push(
                $selected,
                UserPrivacySettingTypeEnum::ROLE_VENUE_RELATED->value,
                UserPrivacySettingTypeEnum::VENUE_VENUES->value,
            );
        }

        if ($user->hasActiveRole(UserParticipationRoleEnum::ORGANIZER->value)) {
            $selected[] = UserPrivacySettingTypeEnum::ROLE_ORGANIZER->value;
        }

        return array_values(array_unique($selected));
    }

    public function forget(User $user): void
    {
        unset($this->allowedTypesCache[(int) $user->canonical()->id]);
    }
}
