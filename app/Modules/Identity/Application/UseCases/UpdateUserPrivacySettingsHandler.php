<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use Illuminate\Support\Facades\DB;

final class UpdateUserPrivacySettingsHandler
{
    /**
     * @param  array<string, array{visibility: string, allowed_user_ids?: array<int, int>}>  $settings
     */
    public function handle(User $user, array $settings): void
    {
        DB::transaction(function () use ($user, $settings): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            foreach (UserPrivacySettingTypeEnum::cases() as $type) {
                $data = $settings[$type->value];
                $visibility = UserPrivacyVisibilityEnum::from($data['visibility']);

                $setting = UserPrivacySetting::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'type' => $type->value],
                    ['visibility' => $visibility->value],
                );

                $allowedUserIds = $visibility === UserPrivacyVisibilityEnum::SELECTED_USERS
                    ? array_values(array_unique($data['allowed_user_ids'] ?? []))
                    : [];

                $setting->allowedUsers()->sync($allowedUserIds);
            }
        }, 3);
    }
}
