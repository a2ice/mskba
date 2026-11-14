<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventVenueBookingProposals;
use App\Modules\Event\Application\Services\EventVenueInterestChat;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventVenueBookingProposal;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversationRead;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Features\FeatureDisabledException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EventVenueInterestConversationController extends Controller
{
    private function interest(string $event, int $interest): EventVenueInterest
    {
        $eventId = Event::query()->whereRouteIdentifier($event)->value('id');
        abort_if($eventId === null, 404);

        return EventVenueInterest::query()->with([
            'event.organizerActor.user', 'venue', 'court', 'proposals.initiator.user',
            'proposals.booking', 'conversation',
        ])->where('event_id', $eventId)->findOrFail($interest);
    }

    public function show(
        Request $request, string $event, int $interest,
        CurrentActorResolver $actors, EventVenueInterestChat $chat, EventVenueInterestService $access,
        VenueBookingAuthorization $bookingAccess, VenueBookingActionState $bookingActions,
    ): Response {
        $thread = $this->interest($event, $interest);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $chat->assertView($thread, $actor);

        $conversation = $thread->conversation ?? VenueBookingConversation::query()->firstOrCreate(
            ['event_venue_interest_id' => $thread->id],
            ['public_id' => (string) Str::uuid()],
        );
        $messages = $conversation->messages()->with('authorActor.user')->latest('id')->limit(100)->get()->sortBy('id');
        $last = $messages->last();
        if ($last !== null && $actor->user !== null) {
            VenueBookingConversationRead::query()->updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $actor->user->canonical()->id],
                ['last_read_message_id' => $last->id, 'read_at' => now()],
            );
        }

        $isOrganizer = $chat->isOrganizer($thread, $actor);
        $booking = $thread->event->sourceBooking;
        $canViewBooking = false;
        $actions = [];
        if ($booking !== null) {
            try {
                $bookingAccess->assertCanView($actor, $booking, $thread->venue);
                $canViewBooking = true;
                $actions = $bookingActions->for($booking, $actor);
            } catch (VenueBookingTransitionException) {
                // Access to a nonbinding interest does not grant financial booking access.
            }
        }

        return ThemeResolver::page('events.interest-thread', [
            'interest' => $thread,
            'conversation' => $conversation,
            'messages' => $messages,
            'isOrganizer' => $isOrganizer,
            'canPropose' => ! $isOrganizer && $actor->user !== null
                && $access->canPropose($actor->user, $thread),
            'canViewBooking' => $canViewBooking,
            'bookingActions' => $actions,
            'canAct' => $thread->status === 'open'
                && $thread->event->booking_id === null
                && $thread->event->participation_confirmation_version === $thread->revision,
        ]);
    }

    public function messages(
        Request $request, string $event, int $interest,
        CurrentActorResolver $actors, EventVenueInterestChat $chat,
    ): JsonResponse {
        $thread = $this->interest($event, $interest);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $chat->assertView($thread, $actor);
        $validated = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $conversation = $thread->conversation;
        if ($conversation === null) {
            return response()->json(['messages' => [], 'booking_status' => $thread->event->sourceBooking?->status?->label()]);
        }
        $items = $conversation->messages()->with('authorActor.user')
            ->where('id', '>', (int) ($validated['after_id'] ?? 0))
            ->orderBy('id')->limit(100)->get();

        return response()->json([
            'messages' => $items->map(fn (VenueBookingMessage $message): array => [
                'id' => $message->id,
                'author' => $message->authorActor?->user?->username ?? 'Система',
                'own' => $message->author_actor_id === $actor->id,
                'type' => $message->type,
                'body' => $message->body,
                'created_at' => $message->created_at->format('d.m.Y H:i'),
            ])->all(),
            'booking_status' => $thread->event->sourceBooking?->status?->label(),
        ]);
    }

    public function send(
        Request $request, string $event, int $interest,
        CurrentActorResolver $actors, EventVenueInterestChat $chat,
    ): RedirectResponse {
        $validated = $request->validate([
            'client_id' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:4000'],
        ]);
        $thread = $this->interest($event, $interest);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $chat->send($thread, $actor, $validated['client_id'], $validated['body']);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Сообщение отправлено.');
    }

    public function propose(
        Request $request, string $event, int $interest,
        CurrentActorResolver $actors, EventVenueBookingProposals $proposals,
    ): RedirectResponse {
        $thread = $this->interest($event, $interest);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $proposals->offer($thread, $actor);
        } catch (VenueBookingPolicyException|FeatureDisabledException|InvalidArgumentException $exception) {
            return back()->with('error', $exception instanceof FeatureDisabledException
                ? 'Аренда временно недоступна.'
                : $exception->getMessage());
        }

        return back()->with('status', 'Предложение отправлено организатору. Бронь пока не создана.');
    }

    public function decline(
        Request $request, string $event, int $interest, int $proposal,
        CurrentActorResolver $actors, EventVenueBookingProposals $proposals,
    ): RedirectResponse {
        $thread = $this->interest($event, $interest);
        $item = EventVenueBookingProposal::query()
            ->where('event_venue_interest_id', $thread->id)->findOrFail($proposal);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $proposals->decline($item, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Предложение отклонено.');
    }
}
