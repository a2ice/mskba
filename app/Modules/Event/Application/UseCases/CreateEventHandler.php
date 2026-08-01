<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\GameManagementService;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Text\CyrillicTransliterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateEventHandler
{
    public function __construct(
        private readonly VenueEventAvailability $availability,
        private readonly CyrillicTransliterator $transliterator,
        private readonly GameManagementService $games,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): Event
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Войдите в аккаунт, чтобы создать мероприятие.');
        }

        $event = DB::transaction(function () use ($actor, $data): Event {
            // Единый порядок блокировок для бронирований: сначала venue, затем bookings/event.
            $venue = Venue::query()->lockForUpdate()->findOrFail($data['venue_id']);
            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $localStart = CarbonImmutable::parse($data['starts_at'], $timezone);
            $durationMinutes = (int) $data['duration_minutes'];

            if ($durationMinutes < 1 || $durationMinutes > 1440) {
                throw new InvalidArgumentException('Мероприятие должно завершиться в течение суток.');
            }

            // PostgreSQL connection uses the application timezone. Keep the
            // local value here: Laravel serializes bindings without an offset,
            // and converting to UTC first would shift the event by three hours.
            $startsAt = $localStart;
            $endsAt = $localStart->addMinutes($durationMinutes);

            $this->availability->assertAvailable($venue, $startsAt, $endsAt);

            $bookingStatus = $venue->hasFreeAccess()
                ? VenueBookingStatusEnum::CONFIRMED
                : VenueBookingStatusEnum::PENDING;

            $event = Event::query()->create([
                'venue_id' => $venue->id,
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'alias' => Str::slug($this->transliterator->transliterate($data['title'])),
                'type' => EventTypeEnum::from($data['type']),
                'status' => $bookingStatus === VenueBookingStatusEnum::CONFIRMED
                    ? EventStatusEnum::PUBLISHED
                    : EventStatusEnum::DRAFT,
                'visibility' => EventVisibilityEnum::from($data['visibility']),
                'description' => $data['description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'max_participants' => $data['max_participants'] ?? null,
            ]);

            $event->booking()->create([
                'venue_id' => $venue->id,
                'created_by_actor_id' => $actor->id,
                'status' => $bookingStatus,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $event->participants()->create([
                'user_id' => $actor->user_id,
                'role' => EventParticipantRoleEnum::ORGANIZER,
                'status' => EventParticipantStatusEnum::CONFIRMED,
                'joined_at' => now(),
            ]);

            if ($event->type === EventTypeEnum::GAME) {
                $this->games->initializeStandalone(
                    $event,
                    (int) $data['team_a_id'],
                    (int) $data['team_b_id'],
                    (int) ($data['side_a_size'] ?? 5),
                    (int) ($data['side_b_size'] ?? 5),
                    GameScoringTypeEnum::from($data['scoring_type'] ?? GameScoringTypeEnum::STREETBALL->value),
                );
            }

            return $event->load(['venue', 'booking', 'participants.user']);
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
