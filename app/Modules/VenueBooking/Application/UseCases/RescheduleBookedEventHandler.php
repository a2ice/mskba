<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\BookedEventRescheduled;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingConflictService;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RescheduleBookedEventHandler
{
    public function __construct(
        private EventManagementAccess $eventAccess,
        private VenueEventAvailability $availability,
        private VenueBookingConflictService $conflicts,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, int $eventId, Actor $actor, int $venueId, CarbonImmutable $startsAt, int $durationMinutes, VenueBookingScopeEnum $scope, ?string $emergencyReason = null): Event
    {
        $this->features->ensureEnabled(VenueRentalFeature::BOOKING_EVENTS);
        if ($durationMinutes < 1 || $durationMinutes > 1440 || ! $startsAt->isFuture()) {
            throw new VenueBookingTransitionException('Некорректный интервал переноса.', 'INVALID_RESCHEDULE_INTERVAL');
        }
        $reference = Event::query()->findOrFail($eventId);
        if ($reference->booking_id === null || $reference->booking_id !== $bookingId) {
            throw new VenueBookingTransitionException('Мероприятие не связано с новой бронью.', 'BOOKING_EVENT_NOT_LINKED');
        }
        $bookingReference = VenueBooking::query()->findOrFail($reference->booking_id);
        $venueIds = collect([$bookingReference->venue_id, $venueId])->unique()->sort()->values();

        return DB::transaction(function () use ($eventId, $bookingReference, $venueId, $venueIds, $startsAt, $durationMinutes, $scope, $actor, $emergencyReason): Event {
            $venues = Venue::query()->whereKey($venueIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $targetVenue = $venues->get($venueId);
            if (! $targetVenue instanceof Venue) {
                throw new VenueBookingTransitionException('Площадка недоступна.', 'VENUE_UNAVAILABLE');
            }
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $candidate = $bookingReference->replicate();
            $candidate->id = $bookingReference->id;
            $candidate->forceFill(['venue_id' => $venueId, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'scope' => $scope]);
            $this->conflicts->lockAndAssertAvailable($targetVenue, $candidate);
            $this->availability->assertAvailable($targetVenue, $startsAt, $endsAt, $bookingReference->id, scope: $scope);

            $event = Event::query()->lockForUpdate()->findOrFail($eventId);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($bookingReference->id);
            if ($event->booking_id !== $booking->id || $booking->status !== VenueBookingStatusEnum::CONFIRMED) {
                throw new VenueBookingTransitionException('Связанная бронь уже изменилась.', 'BOOKING_VERSION_CONFLICT');
            }
            $this->eventAccess->assertAllows($event, $actor, EventResponsibilityPermissionEnum::UPDATE_EVENT);
            $isRequester = $actor->user_id === $booking->requester_user_id;
            $isSuperadmin = $actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false;
            if (! $isRequester && ! $isSuperadmin) {
                throw new VenueBookingTransitionException('Недостаточно прав для переноса брони.', 'BOOKING_FORBIDDEN');
            }
            if ($isSuperadmin && trim((string) $emergencyReason) === '') {
                throw new VenueBookingTransitionException('Для аварийного переноса нужна причина.', 'EMERGENCY_REASON_REQUIRED');
            }
            if ($booking->venue_id === $venueId
                && $booking->scope === $scope
                && $booking->starts_at->equalTo($startsAt)
                && $booking->ends_at->equalTo($endsAt)) {
                return $event;
            }

            $previous = ['venue_id' => $booking->venue_id, 'starts_at' => $booking->starts_at->toIso8601String(), 'ends_at' => $booking->ends_at->toIso8601String(), 'scope' => $booking->scope?->value];
            $nextVersion = $booking->optimistic_version + 1;
            $booking->forceFill(['venue_id' => $venueId, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'scope' => $scope, 'optimistic_version' => $nextVersion])->save();
            $booking->transitions()->create([
                'from_status' => $booking->status, 'to_status' => $booking->status, 'actor_id' => $actor->id,
                'reason' => $emergencyReason, 'metadata' => ['operation' => 'rescheduled', 'previous' => $previous],
                'booking_version' => $nextVersion,
            ]);
            $event->forceFill(['venue_id' => $venueId, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'participation_confirmation_version' => $event->participation_confirmation_version + 1])->save();
            DB::afterCommit(static function () use ($event, $booking): void {
                event(new BookedEventRescheduled($event->id, $booking->id));
                event(new EventChanged($event->id));
            });

            return $event->fresh(['sourceBooking', 'venue']);
        });
    }
}
