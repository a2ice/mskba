<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchPrivacyUsersController extends Controller
{
    public function __invoke(Request $request, SearchDiscoverableUsers $users): JsonResponse
    {
        $viewer = $request->user();
        $query = trim((string) $request->query('query', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $results = $users->handle($viewer, $query)
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

        return response()->json(['users' => $results]);
    }
}
