<?php

namespace App\Modules\Favorites\Application\Services;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;

final readonly class NotifyFavoriteVenueFollowers
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function forEvent(Event $event): void
    {
        if ($event->venue_id === null || $event->status !== EventStatusEnum::PUBLISHED
            || $event->visibility !== EventVisibilityEnum::PUBLIC
            || $event->starts_at === null || $event->starts_at->isPast()) {
            return;
        }

        $this->send(
            (int) $event->venue_id, 'event', $event->id,
            'Новая игра или тренировка на избранной площадке',
            $event->title,
            route('events.show', $event->routeIdentifier(), absolute: false),
        );
    }

    public function forTournament(Tournament $tournament): void
    {
        if ($tournament->default_venue_id === null
            || $tournament->status !== TournamentStatusEnum::CONFIRMED
            || $tournament->starts_on === null || $tournament->starts_on->isPast()) {
            return;
        }

        $this->send(
            (int) $tournament->default_venue_id, 'tournament', $tournament->id,
            'Новый турнир на избранной площадке',
            $tournament->title,
            route('tournaments.show', $tournament->routeIdentifier(), absolute: false),
        );
    }

    private function send(int $venueId, string $type, int $id, string $title, string $body, string $url): void
    {
        Favorite::query()
            ->where('entity_type', 'venue')->where('entity_id', $venueId)
            ->whereNull('trashed_at')->where('notify_upcoming_events', true)
            ->whereNotNull('user_id')
            ->orderBy('id')->chunkById(100, function ($favorites) use ($type, $id, $title, $body, $url, $venueId): void {
                foreach ($favorites as $favorite) {
                    $saved = DB::table('favorite_notification_deliveries')->insertOrIgnore([
                        'user_id' => $favorite->user_id,
                        'entity_type' => $type,
                        'entity_id' => $id,
                        'created_at' => now(),
                    ]);
                    if ($saved === 0) {
                        continue;
                    }
                    $this->notifications->handle(new CreateUserNotificationDTO(
                        userId: (int) $favorite->user_id,
                        type: UserNotificationTypeEnum::REMINDER,
                        title: $title,
                        body: $body,
                        actionUrl: $url,
                        actionText: 'Посмотреть',
                        payload: [
                            'source' => 'favorites.venue.upcoming',
                            'venue_id' => $venueId,
                            $type === 'event' ? 'event_id' : 'tournament_id' => $id,
                        ],
                    ));
                }
            });
    }
}
