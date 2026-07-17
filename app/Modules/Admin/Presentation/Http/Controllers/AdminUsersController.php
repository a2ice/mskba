<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminUsersHandler;
use App\Modules\Admin\Presentation\Http\Requests\BulkChangeUsersRequest;
use App\Modules\Admin\Presentation\Http\Requests\UpdateUserStatusRequest;
use App\Modules\Identity\Application\UseCases\AdminBulkChangeUserDeletionStateHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserStatusHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminUsersController extends Controller
{
    public function index(Request $request, ListAdminUsersHandler $users): Response
    {
        return ThemeResolver::page('admin.users', [
            'users' => $users->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => UserStatusEnum::cases(),
            'roles' => UserSystemRoleEnum::cases(),
        ]);
    }

    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user,
        AdminUpdateUserStatusHandler $updateStatus,
    ): RedirectResponse {
        try {
            $updateStatus->handle($request->user(), $user->id, $request->status());
        } catch (\Exception $e) {
            return redirect()->route('admin.users')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users')->with('success', 'Статус пользователя обновлён.');
    }

    public function bulkDelete(
        BulkChangeUsersRequest $request,
        AdminBulkChangeUserDeletionStateHandler $deletionState,
    ): RedirectResponse {
        try {
            $count = $deletionState->delete($request->user(), $request->userIds());
        } catch (\Exception $e) {
            return redirect()->route('admin.users')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users')->with('success', "Удалено пользователей: {$count}.");
    }

    public function bulkRestore(
        BulkChangeUsersRequest $request,
        AdminBulkChangeUserDeletionStateHandler $deletionState,
    ): RedirectResponse {
        $count = $deletionState->restore($request->user(), $request->userIds());

        return redirect()
            ->route('admin.users', ['deleted' => 1])
            ->with('success', "Восстановлено пользователей: {$count}.");
    }
}
