<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Non-binding demand signal. This record is NEVER queried as an occupying booking
 * and never appears in VenueEventAvailability or price/payment calculations.
 */
final readonly class EventVenueInterestService
{
    public function __construct(
        private VenueMembershipAccess $memberships,
        private VenueCommercialAccess $commercial,
        private CreateUserNotificationHandler $notifications,
    ) {}

    public function canView(User $user, Venue $venue, ?VenueCourt $court = null): bool
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        if ($this->memberships->hasActiveVenueManagementRole($user, $venue)) {
            return true;
        }

        if ($court !== null
            && $this->commercial->allowsForCourt($user, $court, VenuePermissionEnum::VIEW_EVENT_INTERESTS)) {
            return true;
        }

        return $this->commercial->allows($user, $venue, VenuePermissionEnum::VIEW_EVENT_INTERESTS)
            || $this->isSelectedAgent($user, $venue);
    }

    public function canParticipateInThread(User $user, EventVenueInterest $interest): bool
    {
        $user = $user->canonical();
        $organizer = $interest->event?->organizerActor?->user?->canonical();
        if ($organizer?->id === $user->id && $user->isConfirmed() && ! $user->isBlocked()) {
            return true;
        }

        return $this->canView($user, $interest->venue, $interest->court);
    }

    public function canPropose(User $user, EventVenueInterest $interest): bool
    {
        $user = $user->canonical();
        if (! $this->canView($user, $interest->venue, $interest->court)) {
            return false;
        }
        if ($interest->court !== null
            && $this->commercial->allowsForCourt($user, $interest->court, VenuePermissionEnum::PROPOSE_EVENT_BOOKING)) {
            return true;
        }

        return $this->commercial->allows($user, $interest->venue, VenuePermissionEnum::PROPOSE_EVENT_BOOKING)
            || $this->memberships->hasActiveVenueManagementRole($user, $interest->venue);
    }

    public function createFor(Event $event, Actor $actor): EventVenueInterest
    {
        $interest = EventVenueInterest::query()->firstOrCreate(
            ['event_id' => $event->id, 'revision' => $event->participation_confirmation_version],
            [
                'venue_id' => $event->venue_id,
                'venue_court_id' => $event->venue_court_id,
                'created_by_actor_id' => $actor->id,
                'scope' => $event->planned_booking_scope?->value ?? 'whole',
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'status' => 'open',
            ],
        );
        if ($interest->wasRecentlyCreated) {
            VenueBookingConversation::query()->firstOrCreate(
                ['event_venue_interest_id' => $interest->id],
                ['public_id' => (string) \Illuminate\Support\Str::uuid()],
            );
            DB::afterCommit(fn () => $this->notifyRecipients($interest->id));
        }

        return $interest;
    }

    public function revise(Event $event, Actor $actor): void
    {
        $hadOpenInterest = $event->venueInterests()->where('status', 'open')->exists();
        $event->venueInterests()->where('status', 'open')->update(['status' => 'revised']);

        if ($hadOpenInterest) {
            $this->createFor($event, $actor);
        }
    }

    public function markBookingRequested(Event $event): void
    {
        $event->venueInterests()->where('status', 'open')->update(['status' => 'booking_requested']);
    }

    public function reopen(Event $event): void
    {
        $event->venueInterests()->where('status', 'booking_requested')->update(['status' => 'open']);
    }

    public function withdraw(Event $event): void
    {
        $event->venueInterests()->whereIn('status', ['open', 'booking_requested'])->update(['status' => 'withdrawn']);
    }

    /** An unsuccessful or cancelled rental request must not cancel a previously created event. */
    public function releaseLinkedBooking(VenueBooking $booking): void
    {
        $event = Event::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
        if ($event === null || ! $event->booking_deferred) {
            return;
        }

        $event->forceFill(['booking_id' => null, 'booking_snapshot' => null])->save();
        $booking->forceFill(['event_id' => null])->save();
        $this->reopen($event);
        DB::afterCommit(static fn () => event(new EventChanged($event->id)));
    }

    private function notifyRecipients(int $interestId): void
    {
        $interest = EventVenueInterest::query()->with(['venue', 'court', 'event'])->find($interestId);
        if ($interest === null) {
            return;
        }
        $venue = $interest->venue;
        $court = $interest->court;

        $ids = $court !== null
            ? $this->memberships->allowedUserIdsForCourt($court, VenuePermissionEnum::VIEW_EVENT_INTERESTS)
            : $this->memberships->allowedUserIdsForVenue($venue, VenuePermissionEnum::VIEW_EVENT_INTERESTS);
        $owner = $this->memberships->activeOwner($venue);
        if ($owner !== null) {
            $ids[] = $owner->id;
        }
        $agent = $this->selectedAgent($venue);
        if ($agent !== null) {
            $ids[] = $agent->id;
        }

        $event = $interest->event;
        $url = route('events.interests.show', ['event' => $event->routeIdentifier(), 'interest' => $interest->id]);
        $start = $interest->starts_at->setTimezone(
            $venue->schedule()->value('timezone') ?: 'Europe/Moscow',
        )->format('d.m.Y H:i');

        User::query()->whereIn('id', array_unique($ids))->get()
            ->map(fn (User $user): User => $user->canonical())
            ->unique('id')
            ->filter(fn (User $user): bool => $this->canView($user, $venue, $court))
            ->each(function (User $recipient) use ($interest, $event, $venue, $start, $url): void {
                try {
                    $this->notifications->handle(new CreateUserNotificationDTO(
                        userId: $recipient->id,
                        type: UserNotificationTypeEnum::SYSTEM,
                        title: 'Предварительный интерес к аренде',
                        body: 'Планируется мероприятие «'.$event->title.'» на площадке «'.$venue->name.'» — '.$start.'. Это не бронь и не обязательство.',
                        actionUrl: $url,
                        actionText: 'Посмотреть интерес',
                        payload: [
                            'source' => 'event.venue.interest',
                            'delivery_category' => 'system',
                            'event_id' => $event->id,
                            'event_venue_interest_id' => $interest->id,
                            'venue_id' => $venue->id,
                        ],
                    ));
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    private function isSelectedAgent(User $user, Venue $venue): bool
    {
        return $this->selectedAgent($venue)?->id === $user->canonical()->id;
    }

    private function selectedAgent(Venue $venue): ?User
    {
        $assignment = VenueCommissionPolicy::query()->where('venue_id', $venue->id)
            ->first()?->selectedAgent()->with('user')->first();

        $user = $assignment?->user?->canonical();
        return $assignment?->is_active && $user !== null
            && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed()
                ? $user : null;
    }
}
