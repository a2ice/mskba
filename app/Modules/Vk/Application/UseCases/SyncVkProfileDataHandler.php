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
        $user = $user->canonical();
        $profile = Profile::query()->where('user_id', $user->id)->lockForUpdate()->first();

        if ($profile === null) {
            $profile = $user->createProfile([]);
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
