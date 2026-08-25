<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\UseCases\CloseVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Application\UseCases\OpenVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Application\UseCases\RespondVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceResponseValue;
use App\Modules\Coordination\Domain\Exceptions\VenueBookingAttendanceException;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class VenueBookingAttendanceController extends Controller
{
    public function store(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        OpenVenueBookingAttendanceRoundHandler $handler,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'deadline_at' => ['required', 'date', 'after:now'],
            'invited_user_ids' => ['required', 'array', 'min:1'],
            'invited_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'minimum_yes_responses' => ['required', 'integer', 'min:1'],
            'responses_visibility' => ['nullable', Rule::in(['participants', 'organizer'])],
        ]);

        try {
            $round = $handler->handle(
                $venueBooking->id,
                $this->actor($actors, $request),
                CarbonImmutable::parse($validated['deadline_at']),
                array_map('intval', $validated['invited_user_ids']),
                (int) $validated['minimum_yes_responses'],
                $validated['responses_visibility'] ?? 'participants',
            );
        } catch (VenueBookingAttendanceException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json($this->payload($round, true), 201)
            : redirect()->route('venue-booking-attendance.show', $round)->with('status', 'Сбор подтверждений открыт.');
    }

    public function show(Request $request, VenueBookingAttendanceRound $venueBookingAttendanceRound): JsonResponse|Response
    {
        $round = $venueBookingAttendanceRound->load(['booking.venue', 'booking.requester', 'responses.user']);
        [$isOrganizer, $isInvited] = $this->access($round, $request);
        if (! $isOrganizer && ! $isInvited) {
            abort(403);
        }
        $canSeeResponses = $isOrganizer || ($isInvited && $round->responses_visibility === 'participants');

        if ($request->expectsJson()) {
            return response()->json($this->payload($round, $canSeeResponses));
        }

        $ownResponse = $request->user() === null ? null : $round->responses->firstWhere('user_id', $request->user()->canonical()->id);

        return ThemeResolver::page('venue-booking-attendance.show', compact(
            'round', 'isOrganizer', 'isInvited', 'canSeeResponses', 'ownResponse',
        ));
    }

    public function respond(
        Request $request,
        VenueBookingAttendanceRound $venueBookingAttendanceRound,
        RespondVenueBookingAttendanceRoundHandler $handler,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate(['response' => ['required', Rule::enum(VenueBookingAttendanceResponseValue::class)]]);
        try {
            $round = $handler->handle(
                $venueBookingAttendanceRound->id,
                $request->user(),
                VenueBookingAttendanceResponseValue::from($validated['response']),
            );
        } catch (VenueBookingAttendanceException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json($this->payload($round, true))
            : back()->with('status', 'Ответ сохранён. Он не продлевает удержание слота.');
    }

    public function close(
        Request $request,
        VenueBookingAttendanceRound $venueBookingAttendanceRound,
        CurrentActorResolver $actors,
        CloseVenueBookingAttendanceRoundHandler $handler,
    ): JsonResponse|RedirectResponse {
        try {
            $round = $handler->handle($venueBookingAttendanceRound->id, $this->actor($actors, $request));
        } catch (VenueBookingAttendanceException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json($this->payload($round, true))
            : back()->with('status', 'Сбор ответов закрыт.');
    }

    /** @return array{bool,bool} */
    private function access(VenueBookingAttendanceRound $round, Request $request): array
    {
        $userId = $request->user()?->canonical()->id;

        return [
            $userId !== null && $round->booking->requester?->canonical()->id === $userId,
            $userId !== null && $round->responses->contains('user_id', $userId),
        ];
    }

    private function actor(CurrentActorResolver $actors, Request $request): Actor
    {
        return $actors->resolveForRequest($request) ?? abort(403);
    }

    private function error(Request $request, VenueBookingAttendanceException $exception): JsonResponse|RedirectResponse
    {
        $status = $exception->errorCode === 'ATTENDANCE_FORBIDDEN' ? 403 : 409;

        return $request->expectsJson()
            ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $status)
            : back()->withInput()->with('error', $exception->getMessage());
    }

    /** @return array<string, mixed> */
    private function payload(VenueBookingAttendanceRound $round, bool $includeResponses): array
    {
        $round->loadMissing('responses.user');

        return [
            'round_id' => $round->public_id,
            'booking_id' => $round->booking?->public_id,
            'status' => $round->status->value,
            'deadline_at' => $round->deadline_at->toIso8601String(),
            'minimum_yes_responses' => $round->minimum_yes_responses,
            'counts' => [
                'yes' => $round->yes_count,
                'no' => $round->no_count,
                'maybe' => $round->maybe_count,
                'pending' => $round->pending_count,
            ],
            'threshold_reached' => $round->threshold_reached_at !== null,
            'extends_hold' => false,
            'responses' => $includeResponses ? $round->responses->map(fn ($response): array => [
                'user_id' => $response->user_id,
                'name' => $response->user->username,
                'response' => $response->response?->value,
            ])->values() : null,
        ];
    }
}
