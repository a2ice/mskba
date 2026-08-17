<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SearchDiscoverableUsers
{
    /**
     * @param  array<int>  $excludeUserIds
     * @return Collection<int, User>
     */
    public function handle(
        User $viewer,
        string $query,
        array $excludeUserIds = [],
        int $limit = 15,
        ?UserPrivacySettingTypeEnum $requiredAccess = null,
    ): Collection {
        $viewer = $viewer->canonical();
        $rawQuery = trim($query);
        $normalizedQuery = mb_strtolower($rawQuery);

        if (mb_strlen($normalizedQuery) < 2) {
            return collect();
        }

        $excludedCanonicalIds = $this->canonicalIds([
            (int) $viewer->id,
            ...array_map('intval', $excludeUserIds),
        ]);

        return User::query()
            ->with('profile')
            ->whereNull('canonical_user_id')
            ->whereNotIn('id', $excludedCanonicalIds)
            ->where('status', '!=', UserStatusEnum::BLOCKED->value)
            ->where(fn (Builder $privacyQuery) => $this->applyPrivacyFilter(
                $privacyQuery,
                $viewer,
                UserPrivacySettingTypeEnum::DISCOVERABILITY,
            ))
            ->when($requiredAccess !== null, fn (Builder $userQuery) => $userQuery
                ->where(fn (Builder $privacyQuery) => $this->applyPrivacyFilter(
                    $privacyQuery,
                    $viewer,
                    $requiredAccess,
                )))
            ->where(function ($userQuery) use ($normalizedQuery, $rawQuery): void {
                $userQuery
                    ->whereRaw('LOWER(username) LIKE ?', ["%{$normalizedQuery}%"])
                    ->orWhereLike('username', "%{$rawQuery}%")
                    ->orWhereHas('profile', function ($profileQuery) use ($normalizedQuery, $rawQuery): void {
                        $profileQuery
                            ->whereRaw('LOWER(first_name) LIKE ?', ["%{$normalizedQuery}%"])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$normalizedQuery}%"])
                            ->orWhereLike('first_name', "%{$rawQuery}%")
                            ->orWhereLike('last_name', "%{$rawQuery}%");
                    });
            })
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }

    /** @param list<int> $userIds
     * @return list<int>
     */
    private function canonicalIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $resolved = User::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->all();

        return array_values(array_unique([...$ids, ...$resolved]));
    }

    /** @param Builder<User> $query */
    private function applyPrivacyFilter(
        Builder $query,
        User $viewer,
        UserPrivacySettingTypeEnum $type,
    ): void {
        $defaultVisibility = $type->defaultVisibility();
        $viewerIdentityIds = $viewer->identityIds();

        $query
            ->where(function (Builder $missingSettingQuery) use ($type, $defaultVisibility): void {
                $missingSettingQuery
                    ->whereDoesntHave('privacySettings', fn ($settingQuery) => $settingQuery
                        ->where('type', $type->value))
                    ->when(
                        $defaultVisibility !== UserPrivacyVisibilityEnum::EVERYONE,
                        fn (Builder $query) => $query->whereRaw('1 = 0'),
                    );
            })
            ->orWhereHas('privacySettings', fn ($settingQuery) => $settingQuery
                ->where('type', $type->value)
                ->where(function ($visibilityQuery) use ($viewerIdentityIds): void {
                    $visibilityQuery
                        ->where('visibility', UserPrivacyVisibilityEnum::EVERYONE->value)
                        ->orWhere(function ($selectedQuery) use ($viewerIdentityIds): void {
                            $selectedQuery
                                ->where('visibility', UserPrivacyVisibilityEnum::SELECTED_USERS->value)
                                ->whereHas('allowedUsers', fn ($allowedQuery) => $allowedQuery
                                    ->whereKey($viewerIdentityIds));
                        });
                }));
    }
}
