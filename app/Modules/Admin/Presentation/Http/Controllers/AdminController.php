<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\GetAdminDashboardHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

use App\Modules\Admin\Application\UseCases\ListAdminUsersHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Http\Request;

final class AdminController extends Controller
{
    public function index(GetAdminDashboardHandler $dashboard): Response
    {
        return ThemeResolver::page('admin.dashboard', $dashboard->handle());
    }

    public function users(Request $request, ListAdminUsersHandler $users): Response
    {
        return ThemeResolver::page('admin.users', [
            'users' => $users->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => UserStatusEnum::cases(),
            'roles' => UserSystemRoleEnum::cases(),
        ]);
    }
}
