<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingConflictException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueBookingController extends Controller
{
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
    ): JsonResponse|Response {
        $venueBooking->load(['venue', 'requester', 'transitions.actor.user', 'attendanceRounds.responses.user', 'extensionRequests.requestedByActor.user', 'extensionRequests.reviewedByActor.user']);
        $actor = $actors->resolveForRequest($request);

        try {
            $authorization->assertCanView($actor, $venueBooking, $venueBooking->venue);
        } catch (VenueBookingTransitionException $exception) {
            abort($exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409, $exception->getMessage());
        }

        $actionState = $actions->for($venueBooking, $actor);
        if ($request->expectsJson()) {
            return response()->json($this->payload($venueBooking, $actionState));
        }

        $attendanceRound = $venueBooking->attendanceRounds->sortByDesc('id')->first();
        $rentalCoordination = VenueRentalCoordination::query()
            ->with('participants.user')
            ->where('venue_booking_id', $venueBooking->id)
            ->first();
        $isRequester = $request->user()?->canonical()->id === $venueBooking->requester?->canonical()->id;
        $canDecideExtensions = false;
        try {
            $authorization->assertCommercialDecision($actor, $venueBooking->venue);
            $canDecideExtensions = true;
        } catch (VenueBookingTransitionException) {
        }

        return ThemeResolver::page('venue-bookings.show', [
            'booking' => $venueBooking,
            'actions' => $actionState,
            'attendanceRound' => $attendanceRound,
            'attendanceCandidates' => $rentalCoordination?->participants->whereNull('left_at')->values() ?? collect(),
            'isRequester' => $isRequester,
            'canDecideExtensions' => $canDecideExtensions,
        ]);
    }

    public function accept(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, AcceptVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        [$key, $correlation] = $this->commandIdentifiers($request);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $this->version($request), $key, $correlation), 'Заявка принята, слот удерживается.');
    }

    public function reject(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, RejectVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        [$key, $correlation] = $this->commandIdentifiers($request);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $validated['reason'] ?? null, $this->version($request), $key, $correlation), 'Заявка отклонена.');
    }

    public function cancel(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, CancelVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        [$key, $correlation] = $this->commandIdentifiers($request);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $validated['reason'] ?? null, $this->version($request), $key, $correlation), 'Бронь отменена.');
    }

    public function confirm(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, ConfirmVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        [$key, $correlation] = $this->commandIdentifiers($request);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $this->version($request), $key, $correlation), 'Бронь подтверждена.');
    }

    /** @param callable(): VenueBooking $callback */
    private function command(Request $request, callable $callback, string $message): JsonResponse|RedirectResponse
    {
        try {
            $booking = $callback();
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'booking_id' => $booking->public_id,
                'status' => $booking->status->value,
                'version' => $booking->optimistic_version,
            ]);
        }

        return back()->with('status', $message);
    }

    private function error(Request $request, VenueBookingTransitionException $exception): JsonResponse|RedirectResponse
    {
        $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;

        if ($request->expectsJson()) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'suggested_starts_at' => $exception instanceof VenueBookingConflictException
                    ? $exception->suggestedStartsAt
                    : [],
            ], $status);
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

    /** @param array<string, array{allowed: bool, reason: string|null}> $actions
     * @return array<string, mixed>
     */
    private function payload(VenueBooking $booking, array $actions): array
    {
        return [
            'booking_id' => $booking->public_id,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'version' => $booking->optimistic_version,
            'event_id' => $booking->event_id,
            'starts_at' => $booking->starts_at->utc()->toIso8601String(),
            'ends_at' => $booking->ends_at->utc()->toIso8601String(),
            'server_time' => now()->utc()->toIso8601String(),
            'hold_expires_at' => $booking->hold_expires_at?->utc()->toIso8601String(),
            'effective_protection_until' => $booking->effective_protection_until?->utc()->toIso8601String(),
            'actions' => $actions,
            'extensions' => $booking->extensionRequests->map(fn ($extension): array => [
                'id' => $extension->public_id,
                'status' => $extension->status->value,
                'previous_deadline_at' => $extension->previous_deadline_at->utc()->toIso8601String(),
                'requested_until' => $extension->requested_until->utc()->toIso8601String(),
                'reason' => $extension->reason,
                'decision_reason' => $extension->decision_reason,
            ])->values(),
        ];
    }
}
