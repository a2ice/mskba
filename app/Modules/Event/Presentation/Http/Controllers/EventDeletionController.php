<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventDeletionService;
use App\Modules\Event\Domain\Models\Event;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EventDeletionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user()->canonical();
        $events = Event::onlyTrashed()
            ->whereHas('organizerActor', fn ($query) => $query->whereIn('user_id', $user->identityIds()))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('event_lifecycle_changes')
                    ->whereColumn('event_lifecycle_changes.event_id', 'events.id');
            })->latest('deleted_at')->paginate(20);

        return ThemeResolver::page('events.deleted-list', ['events' => $events]);
    }

    public function show(Request $request, string $event, EventDeletionService $deletion): Response|RedirectResponse
    {
        $item = Event::withTrashed()->whereRouteIdentifier($event)->firstOrFail();
        if (! $item->trashed()) {
            return redirect()->route('events.show', $item->routeIdentifier());
        }
        $change = $deletion->latest($item);
        abort_unless($change?->action === 'deleted' && $deletion->mayReadDeleted($item, $request->user()), 404);

        return ThemeResolver::page('events.deleted-show', [
            'event' => $item,
            'change' => $change,
            'owner' => $deletion->isOwner($item, $request->user()),
        ]);
    }

    public function destroy(Request $request, string $event, EventDeletionService $deletion): RedirectResponse
    {
        $item = $deletion->change($event, $request->user(), false, $this->reason($request));

        return redirect()->route('events.lifecycle.show', $item->routeIdentifier())->with('status', 'Мероприятие удалено.');
    }

    public function restore(Request $request, string $event, EventDeletionService $deletion): RedirectResponse
    {
        $item = $deletion->change($event, $request->user(), true, $this->reason($request));

        return redirect()->route('events.show', $item->routeIdentifier())->with('status', 'Мероприятие восстановлено.');
    }

    private function reason(Request $request): string
    {
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }

        return $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']])['reason'];
    }
}
