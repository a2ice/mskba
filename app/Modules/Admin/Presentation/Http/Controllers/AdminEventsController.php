<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminEventsHandler;
use App\Modules\Event\Domain\Models\Event;
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

    public function show(string $event): Response
    {
        $item = Event::withTrashed()
            ->whereRouteIdentifier($event)
            ->with([
                'venue',
                'organizerActor.user.profile',
                'participants.user.profile',
                'participants.responsibilityPermissions',
                'booking',
                'games.sides',
            ])
            ->firstOrFail();

        return ThemeResolver::page('admin.event-show', ['event' => $item]);
    }
}
