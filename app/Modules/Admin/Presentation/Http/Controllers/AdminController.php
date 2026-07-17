<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\GetAdminDashboardHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

final class AdminController extends Controller
{
    public function index(GetAdminDashboardHandler $dashboard): Response
    {
        return ThemeResolver::page('admin.dashboard', $dashboard->handle());
    }
}
