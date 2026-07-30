<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;

final class SearchDiscoverableUsers
{
    /**
     * @param  array<int>  $excludeUserIds
     * @return Collection<int, User>
     */
    public function handle(User $viewer, string $query, array $excludeUserIds = [], int $limit = 15): Collection
    {
        $normalizedQuery = mb_strtolower(trim($query));

        if (mb_strlen($normalizedQuery) < 2) {
            return collect();
        }

        return User::query()
            ->with('profile')
            ->whereNotIn('id', array_values(array_unique([$viewer->getKey(), ...$excludeUserIds])))
            ->where('status', '!=', UserStatusEnum::BLOCKED->value)
            ->where(function ($privacyQuery) use ($viewer): void {
                $privacyQuery
                    ->whereDoesntHave('privacySettings', fn ($settingQuery) => $settingQuery
                        ->where('type', UserPrivacySettingTypeEnum::DISCOVERABILITY->value))
                    ->orWhereHas('privacySettings', fn ($settingQuery) => $settingQuery
                        ->where('type', UserPrivacySettingTypeEnum::DISCOVERABILITY->value)
                        ->where(function ($visibilityQuery) use ($viewer): void {
                            $visibilityQuery
                                ->where('visibility', UserPrivacyVisibilityEnum::EVERYONE->value)
                                ->orWhere(function ($selectedQuery) use ($viewer): void {
                                    $selectedQuery
                                        ->where('visibility', UserPrivacyVisibilityEnum::SELECTED_USERS->value)
                                        ->whereHas('allowedUsers', fn ($allowedQuery) => $allowedQuery
                                            ->whereKey($viewer->getKey()));
                                });
                        }));
            })
            ->where(function ($userQuery) use ($normalizedQuery): void {
                $userQuery
                    ->whereRaw('LOWER(username) LIKE ?', ["%{$normalizedQuery}%"])
                    ->orWhereHas('profile', function ($profileQuery) use ($normalizedQuery): void {
                        $profileQuery
                            ->whereRaw('LOWER(first_name) LIKE ?', ["%{$normalizedQuery}%"])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$normalizedQuery}%"]);
                    });
            })
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }
}
