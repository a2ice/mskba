<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class HomeTodayEventMapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $now = CarbonImmutable::now($timezone);
        $today = $now->toDateString();

        // Reuse the same public participation invariants as JoinEventHandler,
        // additionally requiring a geocoded and publicly visible venue.
        $events = Event::query()
            ->where('status', EventStatusEnum::PUBLISHED->value)
            ->where('visibility', EventVisibilityEnum::PUBLIC->value)
            ->whereDate('starts_at', $today)
            ->where('ends_at', '>', $now)
            ->whereHas('venue', fn ($venues) => $venues
                ->where('status', VenueStatusEnum::CONFIRMED->value)
                ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
                ->whereHas('location.address', fn ($addresses) => $addresses
                    ->whereNotNull('latitude')->whereNotNull('longitude')))
            ->with(['venue.location.address', 'venue.media', 'primaryGame'])
            ->withCount(['participants as confirmed_participants_count' => fn ($participants) => $participants
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->whereColumn('confirmation_version', 'events.participation_confirmation_version')])
            ->orderBy('starts_at')->limit(200)->get()
            ->filter(function (Event $event): bool {
                if ($event->max_participants !== null
                    && $event->confirmed_participants_count >= $event->max_participants) {
                    return false;
                }

                if ($event->type === EventTypeEnum::GAME
                    && $event->primaryGame !== null
                    && ! $event->primaryGame->acceptsAdmissions()) {
                    return false;
                }

                return true;
            })->values()->map(function (Event $event) use ($timezone): array {
                $venue = $event->venue;
                $address = $venue->location->address;
                $photo = $venue->media->sortByDesc('is_featured')->first();

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'type' => $event->type->value,
                    'type_label' => $event->type->label(),
                    'starts_at' => $event->starts_at->setTimezone($timezone)->format('H:i'),
                    'ends_at' => $event->ends_at->setTimezone($timezone)->format('H:i'),
                    'venue_id' => $venue->id,
                    'venue_name' => $venue->name,
                    'address' => $venue->raw_address ?: $address->full_address,
                    'latitude' => (float) $address->latitude,
                    'longitude' => (float) $address->longitude,
                    'image_url' => $photo?->publicUrl(),
                    'url' => route('events.show', $event->routeIdentifier()),
                    'spots_left' => $event->max_participants === null ? null
                        : max(0, $event->max_participants - $event->confirmed_participants_count),
                ];
            })->all();

        return response()->json([
            'date' => $today, 'timezone' => $timezone,
            'count' => count($events), 'events' => $events,
        ])->header('Cache-Control', 'public, max-age=30');
    }
}
