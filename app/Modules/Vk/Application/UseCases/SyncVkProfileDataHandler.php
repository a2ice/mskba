<?php

namespace App\Modules\Vk\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;

final class SyncVkProfileDataHandler
{
    public function handle(User $user, VkUserIdentityDTO $identity): Profile
    {
        // Resolve and lock the canonical status in the caller's authentication
        // transaction. VK data must never silently update confirmed attributes.
        $canonicalId = $user->canonical()->id;
        $user = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
        $profile = Profile::query()->where('user_id', $user->id)->lockForUpdate()->first();

        if ($profile === null) {
            $profile = $user->createProfile([]);
        }

        // Preserve the existing registration/onboarding data import for
        // unconfirmed accounts. After confirmation the user must submit a
        // profile change request; linking or reauthenticating VK is not consent
        // to overwrite verified identity attributes.
        if ($user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
            return $profile;
        }

        $attributes = [];
        $this->fillEmpty($attributes, 'first_name', $profile->first_name, $identity->firstName);
        $this->fillEmpty($attributes, 'last_name', $profile->last_name, $identity->lastName);

        if ($profile->gender === null && $identity->gender !== null) {
            $gender = UserGenderEnum::tryFrom($identity->gender);
            if ($gender !== null) {
                $attributes['gender'] = $gender;
            }
        }

        if ($profile->birth_date === null && $identity->birthDate !== null) {
            $attributes['birth_date'] = $identity->birthDate;
        }

        if ($attributes !== []) {
            $profile->forceFill($attributes)->save();
        }

        return $profile->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function fillEmpty(array &$attributes, string $key, mixed $current, ?string $incoming): void
    {
        if (($current === null || trim((string) $current) === '') && $incoming !== null && trim($incoming) !== '') {
            $attributes[$key] = trim($incoming);
        }
    }
}
