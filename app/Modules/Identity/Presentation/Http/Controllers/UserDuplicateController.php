<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\UserDuplicateSelfServiceProofStore;
use App\Modules\Identity\Application\UseCases\ResolveUserDuplicateHandler;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class UserDuplicateController extends Controller
{
    public function show(
        Request $request,
        UserDuplicate $userDuplicate,
        UserDuplicateSelfServiceProofStore $proofs,
    ): Response {
        $this->assertVisibleToUser($request, $userDuplicate, $proofs);

        return ThemeResolver::page('account.user-duplicate', [
            'duplicate' => $userDuplicate->loadMissing([
                'user.profile',
                'user.telegramAccount',
                'duplicateUser.profile',
                'duplicateUser.telegramAccount',
                'evidence',
            ]),
        ]);
    }

    public function merge(
        Request $request,
        UserDuplicate $userDuplicate,
        ResolveUserDuplicateHandler $resolve,
        UserDuplicateSelfServiceProofStore $proofs,
    ): RedirectResponse {
        $this->assertVisibleToUser($request, $userDuplicate, $proofs);

        $validated = $request->validate([
            'canonical_user_id' => ['required', 'integer'],
        ]);

        try {
            $canonical = $resolve->merge(
                candidate: $userDuplicate,
                canonicalUserId: (int) $validated['canonical_user_id'],
                resolvedBy: $request->user(),
                selfService: true,
                selfServiceSessionId: $request->session()->getId(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        auth()->login($canonical, true);
        $request->session()->regenerate();

        return redirect()->route('account')->with('success', 'Аккаунты объединены. Вы продолжаете работу в основном аккаунте.');
    }

    private function assertVisibleToUser(
        Request $request,
        UserDuplicate $candidate,
        UserDuplicateSelfServiceProofStore $proofs,
    ): void {
        abort_unless($candidate->status === UserDuplicateStatusEnum::PENDING, 404);

        $user = $request->user()->canonical();
        $pairIds = [(int) $candidate->user_id, (int) $candidate->duplicate_user_id];

        abort_unless(in_array((int) $user->id, $pairIds, true), 403);
        abort_unless(
            $proofs->has($candidate, $user, $request->session()->getId()),
            403,
            'Подтверждение Telegram устарело. Повторно подтвердите Telegram перед объединением аккаунтов.',
        );
    }
}
