<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
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
    ) {}

    /** @param array{venue_id: int, title: string, type: string, visibility: string, description?: string|null, starts_at: string, ends_at: string, max_participants?: int|null} $data */
    public function handle(Actor $actor, array $data): Event
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Войдите в аккаунт, чтобы создать мероприятие.');
        }

        return DB::transaction(function () use ($actor, $data): Event {
            // Единый порядок блокировок для бронирований: сначала venue, затем bookings/event.
            $venue = Venue::query()->lockForUpdate()->findOrFail($data['venue_id']);
            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $startsAt = CarbonImmutable::parse($data['starts_at'], $timezone)->utc();
            $endsAt = CarbonImmutable::parse($data['ends_at'], $timezone)->utc();

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

            return $event->load(['venue', 'booking', 'participants.user']);
        });
    }
}
