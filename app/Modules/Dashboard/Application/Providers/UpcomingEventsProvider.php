<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class UpcomingEventsProvider implements DashboardDataProvider
{
    public function __construct(
        private ShowEventHandler $events,
        private CurrentActorResolver $actors,
    ) {}

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();

        return $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed();
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->isAvailable($user, $settings), 404);
        $ids = $user->identityIds();
        $now = now();
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $query = Event::query()->where('status', EventStatusEnum::PUBLISHED->value)
            ->whereNotNull('starts_at')->where('ends_at', '>', $now)
            ->forActiveParticipation($ids)
            ->with(['venue:id,name', 'organizerActor:id,user_id'])
            ->orderBy('starts_at')->orderBy('id');

        $total = 0;
        $items = [];
        $actor = null;
        foreach ($query->lazy(100) as $event) {
            // A sporting roster or admission is not an ACL for a private event.
            // Use the existing detail authorization, including revoked permissions.
            if ($event->visibility !== EventVisibilityEnum::PUBLIC) {
                $actor ??= $this->actors->resolve($user, null);
                try {
                    $this->events->handle($event->routeIdentifier(), $actor);
                } catch (ModelNotFoundException) {
                    continue;
                }
            }
            $total++;
            if (count($items) >= $limit) {
                continue;
            }
            $items[] = [
                'id' => (int) $event->id,
                'title' => $event->title,
                'venue_name' => $event->venue?->name,
                'starts_at' => $event->starts_at->utc()->toIso8601String(),
                'ends_at' => $event->ends_at->utc()->toIso8601String(),
                'time_confirmed' => (bool) $event->time_confirmed,
                'type' => $event->type->value,
                'role' => in_array((int) $event->organizerActor?->user_id, $ids, true) ? 'organizer' : 'participant',
                'url' => route('events.show', $event->routeIdentifier(), false),
            ];
        }

        return new DashboardWidgetData($total, $items);
    }
}
