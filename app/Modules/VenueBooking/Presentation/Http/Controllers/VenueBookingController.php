<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\VenueBooking\Application\Queries\GetBookingDetails;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\GetContributionSummaryHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingConflictException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueBookingController extends Controller
{
    public function __construct(private readonly GetBookingDetails $details) {}

    public function store(
        Request $request,
        CurrentActorResolver $actors,
        RequestVenueBookingHandler $bookings,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate(['quote_id' => ['required', 'uuid']]);
        [$idempotencyKey, $correlationId] = $this->commandIdentifiers($request);

        try {
            $booking = $bookings->handle($actors->resolveForRequest($request), $validated['quote_id'], $idempotencyKey, $correlationId);
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['booking_id' => $booking->public_id, 'status' => $booking->status->value], 201);
        }

        return redirect()->route('account.venue-bookings.show', $booking)->with('status', 'Заявка на аренду отправлена.');
    }

    public function show(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        VenueBookingAuthorization $authorization,
        VenueBookingActionState $actions,
        GetContributionSummaryHandler $contributions,
        VenueCommercialAccess $commercialAccess,
        VenueUserRestrictionService $restrictions,
    ): JsonResponse|Response {
        $venueBooking->load(['venue', 'event', 'eventIntent', 'requester', 'transitions.actor.user', 'attendanceRounds.responses.user', 'extensionRequests.requestedByActor.user', 'extensionRequests.reviewedByActor.user', 'paymentAttempt']);
        $actor = $actors->resolveForRequest($request);

        try {
            $authorization->assertCanView($actor, $venueBooking, $venueBooking->venue);
        } catch (VenueBookingTransitionException $exception) {
            abort($exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409, $exception->getMessage());
        }

        $actionState = $actions->for($venueBooking, $actor);
        $detailsPayload = $this->details->handle($venueBooking, $actor);
        if ($request->expectsJson()) {
            return response()->json($detailsPayload);
        }

        $attendanceRound = $venueBooking->attendanceRounds->sortByDesc('id')->first();
        $rentalCoordination = VenueRentalCoordination::query()
            ->with('participants.user')
            ->where('venue_booking_id', $venueBooking->id)
            ->first();
        $conversation = null;
        $conversationUnread = (int) data_get($detailsPayload, 'conversation.unread_count', 0);
        $contributionSummary = null;
        if (config('features.venue_rental.contributions')) {
            try {
                $contributionSummary = $contributions->handle($venueBooking, $actor, $request->route()?->getName());
            } catch (VenueBookingTransitionException) {
                // Commercial viewers do not automatically receive participant contribution data.
            }
        }
        if (config('features.venue_rental.conversations')) {
            $conversation = VenueBookingConversation::query()
                ->with(['messages' => fn ($query) => $query->with('authorActor.user')->latest('id')->limit(30), 'readMarkers'])
                ->where('venue_booking_id', $venueBooking->id)
                ->first();
            if ($actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'auditable_type' => VenueBooking::class,
                    'auditable_id' => $venueBooking->id, 'event' => 'conversation_viewed_for_support',
                    'old_values' => [], 'new_values' => [], 'metadata' => ['route' => $request->route()?->getName()],
                ]);
            }
        }
        $currentUser = $request->user()?->canonical();
        $isRequester = $currentUser?->id === $venueBooking->requester?->canonical()->id;
        $isAdministrator = $currentUser !== null
            && $currentUser->isConfirmed()
            && $currentUser->system_role->atLeast(UserSystemRoleEnum::ADMIN);
        $rentalRequesterRestriction = null;
        if ($isAdministrator && $venueBooking->requester !== null) {
            $rentalRequesterRestriction = $restrictions->active(
                $venueBooking->venue,
                $venueBooking->requester,
                VenueUserRestrictionTypeEnum::RENTAL_REQUEST,
            );
        }

        $canViewPayment = $isRequester || ($actor->user !== null
            && $commercialAccess->allows($actor->user, $venueBooking->venue, VenuePermissionEnum::VIEW_PAYMENTS));
        $canDecideExtensions = false;
        $canConfirmPayment = false;
        try {
            $authorization->assertCommercialDecision($actor, $venueBooking->venue);
            $canDecideExtensions = true;
        } catch (VenueBookingTransitionException) {
        }
        try {
            $authorization->assertCanConfirmPayment($actor, $venueBooking->venue);
            $canConfirmPayment = true;
        } catch (VenueBookingTransitionException) {
        }

        return ThemeResolver::page('venue-bookings.show', [
            'booking' => $venueBooking,
            'actions' => $actionState,
            'attendanceRound' => $attendanceRound,
            'attendanceCandidates' => $rentalCoordination?->participants->whereNull('left_at')->values() ?? collect(),
            'isRequester' => $isRequester,
            'isAdministrator' => $isAdministrator,
            'rentalRequesterRestriction' => $rentalRequesterRestriction,
            'canDecideExtensions' => $canDecideExtensions,
            'canConfirmPayment' => $canConfirmPayment,
            'canViewPayment' => $canViewPayment,
            'conversation' => $conversation,
            'conversationUnread' => $conversationUnread,
            'contributionSummary' => $contributionSummary,
        ]);
    }

    public function accept(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, AcceptVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        [$key, $correlation] = $this->commandIdentifiers($request);
        $actor = $actors->resolveForRequest($request);

        return $this->command($request, $venueBooking, $actor, fn () => $handler->handle($venueBooking->id, $actor, $this->version($request), $key, $correlation), 'Заявка принята, слот удерживается.');
    }

    public function reject(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, RejectVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        [$key, $correlation] = $this->commandIdentifiers($request);
        $actor = $actors->resolveForRequest($request);

        return $this->command($request, $venueBooking, $actor, fn () => $handler->handle($venueBooking->id, $actor, $validated['reason'] ?? null, $this->version($request), $key, $correlation), 'Заявка отклонена.');
    }

    public function cancel(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, CancelVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        [$key, $correlation] = $this->commandIdentifiers($request);
        $actor = $actors->resolveForRequest($request);

        return $this->command($request, $venueBooking, $actor, fn () => $handler->handle($venueBooking->id, $actor, $validated['reason'] ?? null, $this->version($request), $key, $correlation), 'Бронь отменена.');
    }

    public function confirm(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, ConfirmVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        [$key, $correlation] = $this->commandIdentifiers($request);
        $actor = $actors->resolveForRequest($request);

        return $this->command($request, $venueBooking, $actor, fn () => $handler->handle($venueBooking->id, $actor, $this->version($request), $key, $correlation), 'Бронь подтверждена.');
    }

    /** @param callable(): VenueBooking $callback */
    private function command(Request $request, VenueBooking $reference, Actor $actor, callable $callback, string $message): JsonResponse|RedirectResponse
    {
        try {
            $booking = $callback();
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception, $reference, $actor);
        }

        if ($request->expectsJson()) {
            return response()->json($this->details->handle($booking, $actor));
        }

        return back()->with('status', $message);
    }

    private function error(Request $request, VenueBookingTransitionException $exception, ?VenueBooking $booking = null, ?Actor $actor = null): JsonResponse|RedirectResponse
    {
        $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;

        if ($request->expectsJson()) {
            $payload = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'suggested_starts_at' => $exception instanceof VenueBookingConflictException
                    ? $exception->suggestedStartsAt
                    : [],
            ];
            if ($exception->errorCode === 'BOOKING_VERSION_CONFLICT' && $booking !== null && $actor !== null) {
                $payload['current_state'] = $this->details->handle($booking->fresh(), $actor);
            }

            return response()->json($payload, $status);
        }

        return back()->withInput()->with('error', $exception->getMessage());
    }

    private function version(Request $request): ?int
    {
        $value = $request->input('version');

        return $value === null ? null : (int) $value;
    }

    /** @return array{string, string|null} */
    private function commandIdentifiers(Request $request): array
    {
        $key = $request->header('Idempotency-Key', $request->input('idempotency_key'));
        validator(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        $correlation = $request->header('X-Correlation-ID', $request->input('correlation_id'));
        validator(['correlation' => $correlation], ['correlation' => ['nullable', 'uuid']])->validate();

        return [(string) $key, $correlation === null ? null : (string) $correlation];
    }
}
