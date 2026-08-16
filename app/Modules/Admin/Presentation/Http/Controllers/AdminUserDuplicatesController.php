<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminUserDuplicatesHandler;
use App\Modules\Identity\Application\UseCases\ResolveUserDuplicateHandler;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class AdminUserDuplicatesController extends Controller
{
    public function index(Request $request, ListAdminUserDuplicatesHandler $duplicates): Response
    {
        return ThemeResolver::page('admin.user-duplicates', [
            'duplicates' => $duplicates->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => UserDuplicateStatusEnum::cases(),
        ]);
    }

    public function merge(
        Request $request,
        UserDuplicate $userDuplicate,
        ResolveUserDuplicateHandler $resolve,
    ): RedirectResponse {
        $validated = $request->validate([
            'canonical_user_id' => ['required', 'integer'],
        ]);

        try {
            $resolve->merge(
                candidate: $userDuplicate,
                canonicalUserId: (int) $validated['canonical_user_id'],
                resolvedBy: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('admin.users.duplicates')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.users.duplicates')->with('success', 'Аккаунты объединены.');
    }

    public function reject(
        Request $request,
        UserDuplicate $userDuplicate,
        ResolveUserDuplicateHandler $resolve,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $resolve->reject(
                candidate: $userDuplicate,
                resolvedBy: $request->user(),
                reason: $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('admin.users.duplicates')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.users.duplicates')->with('success', 'Кандидат отклонён.');
    }
}
