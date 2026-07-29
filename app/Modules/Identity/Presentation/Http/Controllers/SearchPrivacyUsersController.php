<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchPrivacyUsersController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $query = trim((string) $request->query('query', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $normalizedQuery = mb_strtolower($query);

        $users = User::query()
            ->with('profile')
            ->whereKeyNot($viewer->getKey())
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
            ->limit(15)
            ->get()
            ->map(function (User $user): array {
                $name = trim(implode(' ', array_filter([
                    $user->profile?->first_name,
                    $user->profile?->last_name,
                ])));

                return [
                    'id' => $user->getKey(),
                    'name' => $name !== '' ? $name : ($user->username ?: "Пользователь #{$user->getKey()}"),
                    'username' => $user->username,
                ];
            });

        return response()->json(['users' => $users]);
    }
}
