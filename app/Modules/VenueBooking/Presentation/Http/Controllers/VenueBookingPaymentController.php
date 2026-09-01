<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\UseCases\ClaimVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\OpenVenueBookingPaymentWindowHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class VenueBookingPaymentController extends Controller
{
    public function open(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, OpenVenueBookingPaymentWindowHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'in:bank_transfer,cash,other'], 'payment_instructions' => ['required', 'string', 'max:4000']]);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $data['method'], $data['payment_instructions']), 'Платёжное окно открыто.');
    }

    public function claim(Request $request, VenueBooking $venueBooking, VenueBookingPaymentAttempt $paymentAttempt, CurrentActorResolver $actors, ClaimVenueBookingPaymentHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'evidence_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        unset($data['evidence_file']);
        if ($request->hasFile('evidence_file')) {
            $file = $request->file('evidence_file');
            $data['evidence_path'] = $file->storeAs(
                'venue-booking-payment-evidence/'.$venueBooking->public_id,
                Str::uuid()->toString().'.'.$file->extension(),
                'local',
            );
            $data['evidence_mime'] = $file->getMimeType();
        }

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $paymentAttempt->id, $actors->resolveForRequest($request), $data), 'Информация об оплате отправлена на проверку.');
    }

    public function confirm(Request $request, VenueBooking $venueBooking, VenueBookingPaymentAttempt $paymentAttempt, CurrentActorResolver $actors, ConfirmVenueBookingPaymentHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $paymentAttempt->id, $actors->resolveForRequest($request), $data['reason'] ?? null), 'Оплата подтверждена. Теперь можно подтвердить бронь.');
    }

    public function reject(Request $request, VenueBooking $venueBooking, VenueBookingPaymentAttempt $paymentAttempt, CurrentActorResolver $actors, RejectVenueBookingPaymentHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->command($request, fn () => $handler->handle($venueBooking->id, $paymentAttempt->id, $actors->resolveForRequest($request), $data['reason']), 'Заявление об оплате отклонено.');
    }

    /** @param callable(): VenueBookingPaymentAttempt $callback */
    private function command(Request $request, callable $callback, string $message): JsonResponse|RedirectResponse
    {
        try {
            $attempt = $callback();
        } catch (VenueBookingTransitionException $exception) {
            $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;

            return $request->expectsJson()
                ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $status)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            $attempt->load('booking');

            return response()->json([
                'payment_attempt_id' => $attempt->public_id,
                'payment_state' => $attempt->status->value,
                'amount_minor' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'window_expires_at' => $attempt->window_expires_at->utc()->toIso8601String(),
                'booking_status' => $attempt->booking->status->value,
                'provider' => $attempt->provider,
                'provider_reference_masked' => $attempt->provider_reference === null ? null : '***'.substr($attempt->provider_reference, -4),
            ]);
        }

        return back()->with('status', $message);
    }
}
