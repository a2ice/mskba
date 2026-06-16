<?php

namespace App\Modules\Audit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\UseCases\ListAuditLogsHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminAuditController extends Controller
{
    public function index(Request $request, ListAuditLogsHandler $logs): Response
    {
        return ThemeResolver::page('admin.audit', [
            'logs' => $logs->handle($request->query()),
            'filters' => $request->query(),
            'entities' => $logs->entities(),
            'events' => $logs->events(),
        ]);
    }
}
