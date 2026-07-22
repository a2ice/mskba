<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminEventsHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminEventsController extends Controller
{
    public function index(Request $request, ListAdminEventsHandler $events): Response
    {
        return ThemeResolver::page('admin.events', [
            'events' => $events->handle($request->query()),
            'statuses' => $events->statuses(),
            'types' => $events->types(),
            'filters' => $request->query(),
        ]);
    }
}
