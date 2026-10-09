<?php

namespace App\Modules\Identity\Application\Services;

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

        return $this->allowedTypesCache[(int) $user->id] = $this->normalizeAllowedTypeValues(
            is_array($allowed) ? $allowed : [],
        );
    }

    /**
     * @param  list<string>  $selectedTypeValues
     */
    public function needsAcceptance(User $user, array $selectedTypeValues): bool
    {
        $user = $user->canonical();

        if (! $this->isEnforcedFor($user)) {
            return false;
        }

        $selectedTypeValues = $this->normalizeAllowedTypeValues($selectedTypeValues);

        if ($selectedTypeValues === []) {
            return false;
        }

        $consent = $user->consents()
            ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
            ->whereNull('revoked_at')
            ->latest('accepted_at')
            ->latest('id')
            ->first();

        if ($consent === null) {
            return true;
        }

        $allowed = is_array($consent->payload)
            ? ($consent->payload['allowed_types'] ?? [])
            : [];
        $allowed = $this->normalizeAllowedTypeValues(is_array($allowed) ? $allowed : []);

        return $allowed !== $selectedTypeValues
            || $consent->document_version !== (string) config('legal.personal_data_distribution_consent_version');
    }

    /** @return list<string> */
    public function selectedForForm(User $user): array
    {
        $user = $user->canonical();

        // Public disclosure must be an affirmative choice during onboarding,
        // not a set of prechecked checkboxes for newly created accounts.
        if ($user->personal_data_distribution_setup_completed_at === null) {
            return [];
        }

        return $this->allowedTypeValues($user);
    }

    public function forget(User $user): void
    {
        unset($this->allowedTypesCache[(int) $user->canonical()->id]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeAllowedTypeValues(array $values): array
    {
        return collect(UserPrivacySettingTypeEnum::distributionTypes())
            ->filter(fn (UserPrivacySettingTypeEnum $type): bool => in_array($type->value, $values, true))
            ->map(fn (UserPrivacySettingTypeEnum $type): string => $type->value)
            ->values()
            ->all();
    }
}
