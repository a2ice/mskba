<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminUsersHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Presentation\Theming\ThemeResolver;
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
}
