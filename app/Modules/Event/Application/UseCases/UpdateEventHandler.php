<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Text\CyrillicTransliterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpdateEventHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly VenueEventAvailability $availability,
        private readonly CyrillicTransliterator $transliterator,
    ) {}

    /**
     * @param array{
     *     venue_id?: int,
     *     title: string,
     *     type: string,
     *     visibility: string,
     *     description?: string|null,
     *     starts_at?: string,
     *     duration_minutes?: int,
     *     max_participants?: int|null
     * } $data
     */
    public function handle(string $identifier, Actor $actor, array $data): Event
    {
        $reference = Event::query()->whereRouteIdentifier($identifier)->firstOrFail(['id', 'venue_id']);
        $requestedVenueId = (int) ($data['venue_id'] ?? $reference->venue_id);
        $venueIds = collect([$reference->venue_id, $requestedVenueId])->unique()->sort()->values();

        return DB::transaction(function () use ($reference, $requestedVenueId, $venueIds, $actor, $data): Event {
            // Общий порядок для бронирований: venue(s) по id -> event -> booking.
            $venues = Venue::query()
                ->whereKey($venueIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $event = Event::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();

            if ($event->venue_id !== $reference->venue_id) {
                throw new InvalidArgumentException('Мероприятие уже было перенесено. Обновите страницу и повторите действие.');
            }

            $this->access->assertCanManage($event, $actor);

            if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
                || $event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Завершённое или отменённое мероприятие нельзя редактировать.');
            }

            $booking = VenueBooking::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentVenue = $venues->get($event->venue_id);
            $targetVenue = $venues->get($requestedVenueId);

            if (! $currentVenue instanceof Venue || ! $targetVenue instanceof Venue) {
                throw new InvalidArgumentException('Выбранная площадка недоступна.');
            }

            $timezone = $targetVenue->schedule()->value('timezone')
                ?: config('app.timezone', 'Europe/Moscow');
            $bookingDataProvided = array_key_exists('venue_id', $data)
                || array_key_exists('starts_at', $data)
                || array_key_exists('duration_minutes', $data);
            $localStart = $bookingDataProvided
                ? CarbonImmutable::parse(
                    $data['starts_at'] ?? $event->starts_at->setTimezone($timezone),
                    $timezone,
                )
                : $event->starts_at;
            $durationMinutes = $bookingDataProvided
                ? (int) ($data['duration_minutes'] ?? $event->starts_at->diffInMinutes($event->ends_at))
                : (int) $event->starts_at->diffInMinutes($event->ends_at);

            if ($durationMinutes < 30 || $durationMinutes > 480 || $durationMinutes % 30 !== 0) {
                throw new InvalidArgumentException('Длительность должна быть от 30 минут до 8 часов с шагом 30 минут.');
            }

            $currentDuration = (int) $event->starts_at->diffInMinutes($event->ends_at);
            $bookingChanged = $bookingDataProvided && (
                $targetVenue->id !== $event->venue_id
                || $localStart->format('Y-m-d H:i') !== $event->starts_at->setTimezone($timezone)->format('Y-m-d H:i')
                || $durationMinutes !== $currentDuration
            );
            $startsAt = $bookingChanged ? $localStart->utc() : $event->starts_at;
            $endsAt = $bookingChanged ? $localStart->addMinutes($durationMinutes)->utc() : $event->ends_at;

            if ($bookingChanged) {
                if (! $currentVenue->hasFreeAccess() || ! $targetVenue->hasFreeAccess()) {
                    throw new InvalidArgumentException(
                        'Площадку и время пока можно менять только для мероприятий на свободных площадках.'
                    );
                }

                $minimumStartsAt = CarbonImmutable::now('UTC')->addMinutes(15)->ceilMinute();

                if ($startsAt->lessThan($minimumStartsAt)) {
                    throw new InvalidArgumentException('Начало должно быть не раньше чем через 15 минут.');
                }

                $this->availability->assertAvailable(
                    $targetVenue,
                    $startsAt,
                    $endsAt,
                    $booking->id,
                );
            }

            $maxParticipants = isset($data['max_participants']) ? (int) $data['max_participants'] : null;
            $confirmedParticipants = $event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->count();

            if ($maxParticipants !== null && $maxParticipants < $confirmedParticipants) {
                throw new InvalidArgumentException('Лимит участников не может быть меньше числа уже записавшихся.');
            }

            $event->forceFill([
                'title' => $data['title'],
                'alias' => Str::slug($this->transliterator->transliterate($data['title'])),
                'type' => EventTypeEnum::from($data['type']),
                'visibility' => EventVisibilityEnum::from($data['visibility']),
                'description' => $data['description'] ?? null,
                'max_participants' => $maxParticipants,
                'venue_id' => $targetVenue->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ])->save();

            if ($bookingChanged) {
                $booking->forceFill([
                    'venue_id' => $targetVenue->id,
                    'status' => VenueBookingStatusEnum::CONFIRMED,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ])->save();
                $event->forceFill(['status' => EventStatusEnum::PUBLISHED])->save();
            }

            return $event->refresh()->load(['venue.schedule', 'booking']);
        });
    }
}
