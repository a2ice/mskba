<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\UseCases\GetContributionSummaryHandler;
use App\Modules\VenueBooking\Application\UseCases\SetContributionCommitmentHandler;
use App\Modules\VenueBooking\Application\UseCases\WithdrawContributionCommitmentHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BookingContributionController extends Controller
{
    public function show(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, GetContributionSummaryHandler $summary): JsonResponse
    {
        try {
            return response()->json($summary->handle($venueBooking, $actors->resolveForRequest($request), $request->route()?->getName()));
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($exception);
        }
    }

    public function store(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        SetContributionCommitmentHandler $set,
        GetContributionSummaryHandler $summary,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'max:32'],
            'share_with_organizer' => ['sometimes', 'boolean'],
        ]);

        try {
            $actor = $actors->resolveForRequest($request);
            $set->handle($venueBooking->id, $actor, $validated['amount'], (bool) ($validated['share_with_organizer'] ?? false));
            $venueBooking->refresh();
            $payload = $summary->handle($venueBooking, $actor, $request->route()?->getName());
        } catch (VenueBookingTransitionException $exception) {
            if ($request->expectsJson()) {
                return $this->error($exception);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json($payload)
            : back()->with('status', 'Обещание обновлено. Списание не выполнялось.');
    }

    public function destroy(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        WithdrawContributionCommitmentHandler $withdraw,
        GetContributionSummaryHandler $summary,
    ): JsonResponse|RedirectResponse {
        try {
            $actor = $actors->resolveForRequest($request);
            $withdraw->handle($venueBooking->id, $actor);
            $venueBooking->refresh();
            $payload = $summary->handle($venueBooking, $actor, $request->route()?->getName());
        } catch (VenueBookingTransitionException $exception) {
            if ($request->expectsJson()) {
                return $this->error($exception);
            }

            return back()->with('error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json($payload)
            : back()->with('status', 'Обещание отозвано.');
    }

    private function error(VenueBookingTransitionException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ], $exception->errorCode === 'CONTRIBUTION_FORBIDDEN' ? 403 : 409);
    }
}
