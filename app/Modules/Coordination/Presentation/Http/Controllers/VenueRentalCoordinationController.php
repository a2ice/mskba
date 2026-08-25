<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\UseCases\CloseVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\ConvertVenueRentalCoordinationToBookingHandler;
use App\Modules\Coordination\Application\UseCases\CreateVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\JoinVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\LeaveVenueRentalCoordinationHandler;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

final class VenueRentalCoordinationController extends Controller
{
    public function store(Request $request, CurrentActorResolver $actors, CreateVenueRentalCoordinationHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'scope' => ['required', Rule::enum(VenueBookingScopeEnum::class)],
            'visibility' => ['nullable', Rule::in(['public', 'private'])],
            'participants_visibility' => ['nullable', Rule::in(['public', 'participants', 'organizer'])],
        ]);

        try {
            $coordination = $handler->handle($this->actor($actors, $request), $validated);
        } catch (InvalidArgumentException|VenueBookingPolicyException $exception) {
            return $this->error($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json($this->payload($coordination, true), 201);
        }

        return redirect()->route('venue-rental-coordinations.show', $coordination)
            ->with('status', 'Сбор участников создан. Время площадки ещё не забронировано.');
    }

    public function show(Request $request, VenueRentalCoordination $venueRentalCoordination): JsonResponse|Response
    {
        $coordination = $venueRentalCoordination->load(['venue', 'organizer', 'booking', 'participants.user']);
        $userId = $request->user()?->canonical()->id;
        $isOrganizer = $userId !== null && $coordination->organizer_user_id === $userId;
        $isParticipant = $userId !== null && $coordination->participants->contains(
            fn ($participant): bool => $participant->user_id === $userId && $participant->left_at === null,
        );
        if ($coordination->visibility === 'private' && ! $isOrganizer && ! $isParticipant) {
            abort(404);
        }

        $canSeeParticipants = $coordination->participants_visibility === 'public'
            || $isOrganizer
            || ($coordination->participants_visibility === 'participants' && $isParticipant);
        $activeParticipants = $coordination->participants->whereNull('left_at')->values();

        if ($request->expectsJson()) {
            return response()->json($this->payload(
                $coordination,
                $canSeeParticipants,
                $activeParticipants->map(static fn ($participant): array => [
                    'user_id' => $participant->user_id,
                    'name' => $participant->user->username,
                ])->all(),
            ));
        }

        return ThemeResolver::page('venue-rental-coordinations.show', compact(
            'coordination', 'activeParticipants', 'isOrganizer', 'isParticipant', 'canSeeParticipants',
        ));
    }

    public function join(Request $request, VenueRentalCoordination $venueRentalCoordination, JoinVenueRentalCoordinationHandler $handler): JsonResponse|RedirectResponse
    {
        return $this->participantCommand($request, fn () => $handler->handle($venueRentalCoordination->id, $request->user()), 'Вы присоединились к сбору.');
    }

    public function leave(Request $request, VenueRentalCoordination $venueRentalCoordination, LeaveVenueRentalCoordinationHandler $handler): JsonResponse|RedirectResponse
    {
        return $this->participantCommand($request, fn () => $handler->handle($venueRentalCoordination->id, $request->user()), 'Вы покинули сбор.');
    }

    public function close(Request $request, VenueRentalCoordination $venueRentalCoordination, CurrentActorResolver $actors, CloseVenueRentalCoordinationHandler $handler): JsonResponse|RedirectResponse
    {
        return $this->participantCommand($request, fn () => $handler->handle($venueRentalCoordination->id, $this->actor($actors, $request)), 'Сбор закрыт.');
    }

    public function convert(Request $request, VenueRentalCoordination $venueRentalCoordination, CurrentActorResolver $actors, ConvertVenueRentalCoordinationToBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        try {
            $booking = $handler->handle($venueRentalCoordination->id, $this->actor($actors, $request), $validated['idempotency_key']);
        } catch (InvalidArgumentException|VenueBookingPolicyException|VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['booking_id' => $booking->public_id, 'status' => $booking->status->value], 201);
        }

        return redirect()->route('account.venue-bookings.show', $booking)->with('status', 'Заявка на аренду отправлена.');
    }

    /** @param callable(): VenueRentalCoordination $callback */
    private function participantCommand(Request $request, callable $callback, string $message): JsonResponse|RedirectResponse
    {
        try {
            $coordination = $callback();
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json($this->payload($coordination, false))
            : back()->with('status', $message);
    }

    private function error(Request $request, Throwable $exception): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $exception->getMessage()], 409)
            : back()->withInput()->with('error', $exception->getMessage());
    }

    private function actor(CurrentActorResolver $actors, Request $request): Actor
    {
        return $actors->resolveForRequest($request) ?? abort(403);
    }

    /** @param list<array{user_id: int, name: string|null}> $participants */
    private function payload(VenueRentalCoordination $coordination, bool $includeParticipants, array $participants = []): array
    {
        return [
            'coordination_id' => $coordination->public_id,
            'status' => $coordination->status->value,
            'status_label' => $coordination->status->label(),
            'venue_id' => $coordination->venue_id,
            'starts_at' => $coordination->starts_at->toIso8601String(),
            'ends_at' => $coordination->ends_at->toIso8601String(),
            'booking_id' => $coordination->booking?->public_id,
            'slot_reserved' => $coordination->booking?->status->occupiesVenue() ?? false,
            'participants' => $includeParticipants ? $participants : null,
        ];
    }
}
