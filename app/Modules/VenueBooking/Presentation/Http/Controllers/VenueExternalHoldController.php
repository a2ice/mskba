<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\Services\VenueExternalHoldManager;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VenueExternalHoldController extends Controller
{
    public function confirm(
        Request $request,
        VenueBooking $venueBooking,
        CurrentActorResolver $actors,
        VenueExternalHoldManager $holds,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate([
            'method' => ['required', 'in:phone,messenger,aggregator,other'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reference' => ['nullable', 'string', 'max:190'],
            'note' => ['required', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:0'],
        ]);
        $timezone = $venueBooking->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $deadline = CarbonImmutable::parse(str_replace('T', ' ', $data['expires_at']), $timezone)->utc();
        try {
            $booking = $holds->confirm(
                $venueBooking->id,
                $actors->resolveForRequest($request),
                $deadline,
                $data['method'],
                $data['reference'] ?? null,
                $data['note'],
                isset($data['version']) ? (int) $data['version'] : null,
            );
        } catch (VenueBookingTransitionException $exception) {
            return $request->expectsJson()
                ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409)
                : back()->withInput()->with('error', $exception->getMessage());
        }
        return $request->expectsJson()
            ? response()->json([
                'booking_id' => $booking->public_id,
                'status' => $booking->status->value,
                'hold_expires_at' => $booking->hold_expires_at?->utc()->toIso8601String(),
            ])
            : back()->with('status', 'Реальный холд подтверждён, время удерживается на MSKBA.');
    }
}
