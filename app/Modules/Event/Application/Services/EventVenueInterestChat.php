<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\EventVenueInterestMessageSentBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class EventVenueInterestChat
{
    public function __construct(
        private EventVenueInterestService $access,
        private VenueMembershipAccess $memberships,
        private CreateUserNotificationHandler $notifications,
    ) {}

    public function assertView(EventVenueInterest $interest, Actor $actor): void
    {
        abort_unless($actor->user !== null
            && $this->access->canParticipateInThread($actor->user, $interest), 403);
    }

    public function isOrganizer(EventVenueInterest $interest, Actor $actor): bool
    {
        return $interest->event->organizerActor?->user?->canonical()->id
            === $actor->user?->canonical()->id;
    }

    public function send(EventVenueInterest $interest, Actor $actor, string $clientId, string $body): VenueBookingMessage
    {
        $this->assertView($interest, $actor);
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Сообщение не может быть пустым.');
        }

        return DB::transaction(function () use ($interest, $actor, $clientId, $body): VenueBookingMessage {
            $conversation = VenueBookingConversation::query()->firstOrCreate(
                ['event_venue_interest_id' => $interest->id],
                ['public_id' => (string) Str::uuid()],
            );
            $message = VenueBookingMessage::query()->firstOrCreate([
                'conversation_id' => $conversation->id,
                'author_actor_id' => $actor->id,
                'client_id' => $clientId,
            ], [
                'public_id' => (string) Str::uuid(),
                'type' => 'text',
                'body' => $body,
            ]);
            if ($message->wasRecentlyCreated) {
                DB::afterCommit(function () use ($interest, $actor, $conversation, $message): void {
                    broadcast(new EventVenueInterestMessageSentBroadcast($conversation->public_id, $message->public_id));
                    $this->notify($interest, $actor, 'Новое сообщение по предзаявке',
                        'Новое сообщение в переписке по мероприятию «'.$interest->event->title.'».');
                });
            }

            return $message->load('authorActor.user');
        });
    }

    public function system(EventVenueInterest $interest, Actor $actor, string $body): void
    {
        $conversation = VenueBookingConversation::query()->firstOrCreate(
            ['event_venue_interest_id' => $interest->id],
            ['public_id' => (string) Str::uuid()],
        );
        $message = VenueBookingMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'author_actor_id' => $actor->id,
            'client_id' => (string) Str::uuid(),
            'type' => 'system',
            'body' => $body,
        ]);
        DB::afterCommit(static fn () => broadcast(new EventVenueInterestMessageSentBroadcast(
            $conversation->public_id, $message->public_id,
        )));
    }

    public function notify(EventVenueInterest $interest, Actor $sender, string $title, string $body): void
    {
        $interest->loadMissing(['venue', 'court', 'event.organizerActor.user']);
        $recipients = $this->isOrganizer($interest, $sender)
            ? ($interest->court !== null
                ? $this->memberships->allowedUserIdsForCourt($interest->court, VenuePermissionEnum::VIEW_EVENT_INTERESTS)
                : $this->memberships->allowedUserIdsForVenue($interest->venue, VenuePermissionEnum::VIEW_EVENT_INTERESTS))
            : [$interest->event->organizerActor?->user?->canonical()->id];
        if ($this->isOrganizer($interest, $sender) && ($owner = $this->memberships->activeOwner($interest->venue)) !== null) {
            $recipients[] = $owner->id;
        }
        $link = route('events.interests.show', ['event' => $interest->event->routeIdentifier(), 'interest' => $interest->id], false);
        User::query()->whereIn('id', array_filter(array_unique($recipients)))->get()
            ->map(fn (User $user) => $user->canonical())->unique('id')
            ->filter(fn (User $user) => $user->id !== $sender->user?->canonical()->id
                && $this->access->canParticipateInThread($user, $interest))
            ->each(fn (User $user) => $this->notifications->handle(new CreateUserNotificationDTO(
                userId: $user->id,
                type: UserNotificationTypeEnum::MESSAGE,
                title: $title,
                body: $body,
                actionUrl: $link,
                actionText: 'Переписка',
                payload: ['source' => 'event.venue.interest.message', 'delivery_category' => 'request',
                    'event_venue_interest_id' => $interest->id, 'venue_id' => $interest->venue_id],
            )));
    }
}
