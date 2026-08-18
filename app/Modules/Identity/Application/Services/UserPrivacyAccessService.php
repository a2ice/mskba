<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserPrivacyAccessService
{
    public function allows(User $subject, ?User $viewer, UserPrivacySettingTypeEnum $type): bool
    {
        $subject = $subject->canonical();
        $viewer = $viewer?->canonical();

        if ($viewer !== null && $viewer->id === $subject->id) {
            return true;
        }

        $setting = $subject->privacySettings()
            ->where('type', $type->value)
            ->first();
        $visibility = $setting?->visibility ?? $type->defaultVisibility();

        return match ($visibility) {
            UserPrivacyVisibilityEnum::EVERYONE => true,
            UserPrivacyVisibilityEnum::NOBODY => false,
            UserPrivacyVisibilityEnum::SELECTED_USERS => $viewer !== null
                && $setting?->allowedUsers()->whereKey($viewer->identityIds())->exists() === true,
        };
    }
}
