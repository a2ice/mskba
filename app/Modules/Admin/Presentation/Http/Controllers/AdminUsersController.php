<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminUsersHandler;
use App\Modules\Admin\Presentation\Http\Requests\BulkChangeUsersRequest;
use App\Modules\Admin\Presentation\Http\Requests\UpdateUserBasicDetailsRequest;
use App\Modules\Admin\Presentation\Http\Requests\UpdateUserOperationalPermissionsRequest;
use App\Modules\Admin\Presentation\Http\Requests\UpdateUserStatusRequest;
use App\Modules\Identity\Application\UseCases\AdminBulkChangeUserDeletionStateHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserBasicDetailsHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserStatusHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminUsersController extends Controller
{
    public function index(
        Request $request,
        ListAdminUsersHandler $users,
        OnlineUserPresence $presence,
        SiteSummaryService $summary,
    ): Response {
        return ThemeResolver::page('admin.users', [
            'users' => $users->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => UserStatusEnum::cases(),
            'roles' => UserSystemRoleEnum::cases(),
            'operationalPermissions' => UserOperationalPermissionEnum::cases(),
            'onlinePresence' => $presence->snapshot(),
            'onlineSummary' => $summary->get(),
        ]);
    }

    public function updateOperationalPermissions(
        UpdateUserOperationalPermissionsRequest $request,
        User $user,
        AdminUpdateUserOperationalPermissionsHandler $updatePermissions,
    ): RedirectResponse {
        $updatePermissions->handle($request->user(), $user->id, $request->permissions());

        return redirect()
            ->route('admin.users')
            ->with('success', 'Операционные права пользователя обновлены.');
    }

    public function edit(User $user): Response|RedirectResponse
    {
        $canonical = $user->canonical();

        if ((int) $canonical->id !== (int) $user->id) {
            return redirect()
                ->route('admin.users.edit', $canonical)
                ->with('info', "Аккаунт #{$user->id} является alias пользователя #{$canonical->id}. Редактируется основной аккаунт.");
        }

        return ThemeResolver::page('admin.user-edit', [
            'editedUser' => $canonical->load('profile'),
        ]);
    }

    public function update(
        UpdateUserBasicDetailsRequest $request,
        User $user,
        AdminUpdateUserBasicDetailsHandler $updateUser,
    ): RedirectResponse {
        $canonical = $user->canonical();
        $updateUser->handle($request->user(), $canonical->id, $request->details());

        return redirect()
            ->route('admin.users.edit', $canonical)
            ->with('success', 'Базовые данные пользователя обновлены.');
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
