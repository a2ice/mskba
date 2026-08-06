<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserPrivacyAccess
{
    public function allows(User $subject, User $viewer, UserPrivacySettingTypeEnum $type): bool
    {
        if ($subject->is($viewer)) {
            return true;
        }

        $setting = $subject->privacySettings()
            ->with('allowedUsers:id')
            ->where('type', $type->value)
            ->first();
        $visibility = $setting?->visibility ?? $type->defaultVisibility();

        return match ($visibility) {
            UserPrivacyVisibilityEnum::EVERYONE => true,
            UserPrivacyVisibilityEnum::SELECTED_USERS => $setting?->allowedUsers
                ->contains(fn (User $allowedUser): bool => $allowedUser->is($viewer)) ?? false,
            UserPrivacyVisibilityEnum::NOBODY => false,
        };
    }
}
