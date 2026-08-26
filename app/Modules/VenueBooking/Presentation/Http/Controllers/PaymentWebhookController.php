<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\VenueBooking\Application\UseCases\HandlePaymentWebhook;
use App\Modules\VenueBooking\Domain\Exceptions\InvalidPaymentWebhookException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, HandlePaymentWebhook $handler): JsonResponse
    {
        $headers = collect($request->headers->all())
            ->map(fn (array $values): string => (string) ($values[0] ?? ''))
            ->all();

        try {
            $receipt = $handler->handle($provider, $request->all(), $headers);
        } catch (InvalidPaymentWebhookException $exception) {
            return response()->json(['code' => 'INVALID_PAYMENT_WEBHOOK', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['event_id' => $receipt->provider_event_id, 'status' => $receipt->status], 202);
    }
}
