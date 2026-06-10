<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListPlaceholderAdminItemsHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminEventsController extends Controller
{
    public function index(Request $request, ListPlaceholderAdminItemsHandler $items): Response
    {
        return ThemeResolver::page('admin.events', [
            'items' => $items->handle('events', $request->query()),
            'filters' => $request->query(),
        ]);
    }
}
