<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Application\UseCases\MarkVenueBookingConversationReadHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversationRead;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Inbox is a second presentation of the existing domain conversation.
 * Mutations deliberately continue through the existing booking / pre-request endpoints.
 */
final class AccountBookingConversationsController extends Controller
{
    public function booking(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        VenueBookingAuthorization $authorization,
        VenueBookingActionState $state,
        AccountConversationRail $rail,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $venueBooking->loadMissing(['venue', 'requester.profile']);
        try {
            $authorization->assertCanView($actor, $venueBooking, $venueBooking->venue);
        } catch (VenueBookingTransitionException) {
            abort(403);
        }
        if ($actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'auditable_type' => VenueBooking::class,
                'auditable_id' => $venueBooking->id, 'event' => 'conversation_viewed_for_support',
                'old_values' => [], 'new_values' => [],
                'metadata' => ['route' => $request->route()?->getName()],
            ]);
        }
        $canWrite = true;
        try {
            $authorization->assertCanWriteConversation($actor, $venueBooking, $venueBooking->venue);
        } catch (VenueBookingTransitionException) {
            $canWrite = false;
        }

        $conversation = VenueBookingConversation::query()->where($venueBooking->conversationLookup())->first();
        [$messages, $hasMore] = $this->recent($conversation);

        return ThemeResolver::page('account.booking-request-conversation', [
            'booking' => $venueBooking,
            'pre' => null,
            'actor' => $actor,
            'conversation' => $conversation,
            'messages' => $messages,
            'hasMore' => $hasMore,
            'canWrite' => $canWrite,
            'statusLabel' => $venueBooking->status->label(),
            'initialActions' => $state->for($venueBooking, $actor),
            'conversationCards' => $rail->items($request->user(), $request),
            'activeConversationId' => 'booking-'.$venueBooking->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preRequest(
        Request $request,
        VenueRentalPreRequest $preRequest,
        CurrentActorResolver $actors,
        VenueRentalPreRequests $service,
        AccountConversationRail $rail,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $service->assertCan($actor, $preRequest);
        if (app(VenueBookingBackofficeAccess::class)->canView($request->user())) {
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'auditable_type' => VenueRentalPreRequest::class,
                'auditable_id' => $preRequest->id, 'event' => 'rental_support_conversation_viewed',
                'old_values' => [], 'new_values' => [],
                'metadata' => ['route' => $request->route()?->getName(), 'venue_id' => $preRequest->venue_id],
            ]);
        }
        $preRequest->loadMissing(['venue', 'court', 'requester']);
        $conversation = $preRequest->conversation;
        [$messages, $hasMore] = $this->recent($conversation);

        return ThemeResolver::page('account.booking-request-conversation', [
            'pre' => $preRequest,
            'booking' => $preRequest->bookings()->latest('id')->first(),
            'actor' => $actor,
            'conversation' => $conversation,
            'messages' => $messages,
            'hasMore' => $hasMore,
            'canWrite' => $service->can($actor->user, $preRequest, 'write'),
            'statusLabel' => $preRequest->status->label(),
            'initialActions' => [],
            'conversationCards' => $rail->items($request->user(), $request),
            'activeConversationId' => 'pre-request-'.$preRequest->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preRequestMessages(
        Request $request,
        VenueRentalPreRequest $preRequest,
        CurrentActorResolver $actors,
        VenueRentalPreRequests $service,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $service->assertCan($actor, $preRequest);
        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        abort_if(isset($data['before_id'], $data['after_id']), 422);
        $conversation = $preRequest->conversation;
        $messages = VenueBookingMessage::query()->with(['authorActor.user'])
            ->where('conversation_id', $conversation->id)
            ->when(isset($data['before_id']), fn ($q) => $q->where('id', '<', $data['before_id']))
            ->when(isset($data['after_id']), fn ($q) => $q->where('id', '>', $data['after_id'])->orderBy('id'))
            ->when(! isset($data['after_id']), fn ($q) => $q->orderByDesc('id'))
            ->limit(31)->get();
        $hasMore = $messages->count() > 30;
        $items = $messages->take(30)->sortBy('id')->values();
        $latestBooking = $preRequest->bookings()->latest('id')->first();
        $isCustomer = $actor->user?->canonical()->id === $preRequest->requester_user_id;
        $active = $preRequest->status === VenueRentalPreRequestStatus::ACTIVE;
        $pendingOffer = $latestBooking !== null
            && $latestBooking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::REQUESTED
            && $latestBooking->proposal_accepted_at === null
            && $latestBooking->proposal_expires_at?->isFuture();
        // An expired offer may be replaced by the domain service, which cancels
        // the old proposal under its lock before issuing the next ordinary booking.
        $canReplaceExpired = $latestBooking !== null
            && $latestBooking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::REQUESTED
            && $latestBooking->proposal_accepted_at === null
            && $latestBooking->proposal_expires_at?->isPast();

        return response()->json([
            'conversation_id' => $conversation->public_id,
            'messages' => $items->map(fn (VenueBookingMessage $message): array => [
                'id' => $message->id,
                'message_id' => $message->public_id,
                'body' => $message->body,
                'type' => $message->type,
                'author' => $message->authorActor?->user?->username,
                'is_own' => $message->authorActor?->user_id === $actor->user_id,
                'created_at' => $message->created_at->utc()->toIso8601String(),
                'attachment' => $message->attachment_path === null ? null : [
                    'name' => $message->attachment_name,
                    'mime' => $message->attachment_mime,
                ],
            ])->all(),
            'has_more' => $hasMore,
            'status_label' => $preRequest->status->label(),
            'can_write' => $service->can($actor->user, $preRequest, 'write'),
            'actions' => [
                'offer' => $active && ! $isCustomer && $service->can($actor->user, $preRequest, 'offer')
                    && ! $pendingOffer && ($latestBooking === null || $latestBooking->status->isTerminal() || $canReplaceExpired),
                'accept_offer' => $active && $isCustomer && $pendingOffer,
                'decline_offer' => $active && $isCustomer && $pendingOffer,
                'withdraw' => $active && $isCustomer && ! $pendingOffer,
            ],
            'proposal_id' => $pendingOffer ? $latestBooking->id : null,
            'linked_booking_url' => $latestBooking === null ? null : route('account.venue-bookings.show', $latestBooking),
        ]);
    }

    public function readBooking(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        VenueBookingAuthorization $authorization,
        MarkVenueBookingConversationReadHandler $readConversation,
        MarkConversationNotificationsReadHandler $readNotifications,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $venueBooking->loadMissing('venue');
        try {
            $authorization->assertCanView($actor, $venueBooking, $venueBooking->venue);
        } catch (VenueBookingTransitionException) {
            abort(403);
        }

        $conversation = VenueBookingConversation::query()->where($venueBooking->conversationLookup())->first();
        if ($conversation !== null) {
            $messageId = $conversation->messages()->latest('id')->value('id');
            $readConversation->handle($venueBooking->id, $conversation->id, $actor, $messageId === null ? null : (int) $messageId);
        }

        return response()->json([
            'unread_count' => $readNotifications->handle($request->user(), bookingPublicId: $venueBooking->public_id),
        ]);
    }

    public function readPreRequest(
        Request $request,
        VenueRentalPreRequest $preRequest,
        CurrentActorResolver $actors,
        VenueRentalPreRequests $service,
        MarkConversationNotificationsReadHandler $readNotifications,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $service->assertCan($actor, $preRequest);
        $conversation = $preRequest->conversation;
        DB::transaction(static function () use ($conversation, $actor): void {
            VenueBookingConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $lastId = (int) ($conversation->messages()->latest('id')->value('id') ?? 0);
            if ($lastId === 0) {
                return;
            }
            $marker = VenueBookingConversationRead::query()->where('conversation_id', $conversation->id)
                ->where('user_id', $actor->user?->canonical()->id)->lockForUpdate()->first();
            if ($marker !== null && (int) $marker->last_read_message_id >= $lastId) {
                return;
            }
            $marker ??= new VenueBookingConversationRead([
                'conversation_id' => $conversation->id,
                'user_id' => $actor->user?->canonical()->id,
            ]);
            $marker->fill(['last_read_message_id' => $lastId, 'read_at' => now()])->save();
        });

        // A proposal is still part of this pre-request thread: acknowledge its
        // booking lifecycle notices along with ordinary pre-request notifications.
        foreach ($preRequest->bookings()->pluck('public_id') as $linkedPublicId) {
            $readNotifications->handle($request->user(), bookingPublicId: $linkedPublicId);
        }

        return response()->json([
            'unread_count' => $readNotifications->handle($request->user(), preRequestId: $preRequest->id),
        ]);
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: bool} */
    private function recent(?VenueBookingConversation $conversation): array
    {
        if ($conversation === null) {
            return [collect(), false];
        }

        $latest = $conversation->messages()->with('authorActor.user')->latest('id')->limit(31)->get();

        return [$latest->take(30)->reverse()->values(), $latest->count() > 30];
    }
}
