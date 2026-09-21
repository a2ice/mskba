<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletTransferRecipientSearchController extends Controller
{
    public function __invoke(Request $request, SearchDiscoverableUsers $users): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        /** @var User|null $viewer */
        $viewer = $request->user();
        abort_if($viewer === null, 401);

        $candidates = $users->handle($viewer, (string) $validated['q'], limit: 10)
            ->filter(fn (User $user): bool => $user->isConfirmed())
            ->map(function (User $user): array {
                $name = trim(implode(' ', array_filter([
                    $user->profile?->first_name,
                    $user->profile?->last_name,
                ])));
                $handle = trim((string) ($user->nickname ?: $user->username));

                return [
                    'id' => (int) $user->id,
                    'name' => $name !== ''
                        ? $name
                        : ($handle !== '' ? '@'.$handle : 'Пользователь #'.$user->id),
                    'meta' => $handle !== '' ? '@'.$handle : '',
                ];
            })
            ->values();

        return response()->json(['candidates' => $candidates]);
    }
}
