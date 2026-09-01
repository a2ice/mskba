<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\UseCases\ApproveVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExtensionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VenueBookingExtensionController extends Controller
{
    public function store(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, RequestVenueBookingExtensionHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'requested_until' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command($request, fn () => $handler->handle(
            $venueBooking->id,
            $actors->resolveForRequest($request),
            CarbonImmutable::parse($validated['requested_until']),
            $validated['reason'],
        ), 'Запрос на продление отправлен.');
    }

    public function approve(Request $request, VenueBooking $venueBooking, VenueBookingExtensionRequest $extensionRequest, CurrentActorResolver $actors, ApproveVenueBookingExtensionHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $extensionRequest->id, $actors->resolveForRequest($request), $validated['reason'] ?? null), 'Продление одобрено.');
    }

    public function reject(Request $request, VenueBooking $venueBooking, VenueBookingExtensionRequest $extensionRequest, CurrentActorResolver $actors, RejectVenueBookingExtensionHandler $handler): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $extensionRequest->id, $actors->resolveForRequest($request), $validated['reason'] ?? null), 'Продление отклонено.');
    }

    public function cancel(Request $request, VenueBooking $venueBooking, VenueBookingExtensionRequest $extensionRequest, CurrentActorResolver $actors, CancelVenueBookingExtensionHandler $handler): JsonResponse|RedirectResponse
    {
        return $this->command($request, fn () => $handler->handle($venueBooking->id, $extensionRequest->id, $actors->resolveForRequest($request)), 'Запрос на продление отменён.');
    }

    /** @param callable(): VenueBookingExtensionRequest $callback */
    private function command(Request $request, callable $callback, string $message): JsonResponse|RedirectResponse
    {
        try {
            $extension = $callback();
        } catch (VenueBookingTransitionException $exception) {
            $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;
            if ($request->expectsJson()) {
                return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $status);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            $extension->load('booking');

            return response()->json([
                'extension_request_id' => $extension->public_id,
                'status' => $extension->status->value,
                'requested_until' => $extension->requested_until->utc()->toIso8601String(),
                'effective_protection_until' => $extension->booking->effective_protection_until?->utc()->toIso8601String(),
                'booking_version' => $extension->booking->optimistic_version,
            ]);
        }

        return back()->with('status', $message);
    }
}
