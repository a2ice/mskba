<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\GetAdminSettingsHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

final class AdminSettingsController extends Controller
{
    public function index(GetAdminSettingsHandler $settings): Response
    {
        return ThemeResolver::page('admin.settings', [
            'groups' => $settings->handle(),
        ]);
    }
}
