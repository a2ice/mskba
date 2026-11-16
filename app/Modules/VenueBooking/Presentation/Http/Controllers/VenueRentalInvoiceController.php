<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\Services\VenueRentalInvoiceManager;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VenueRentalInvoiceController extends Controller
{
    public function issue(
        Request $request, VenueBooking $venueBooking,
        CurrentActorResolver $actors, VenueRentalInvoiceManager $invoices,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate(['template_id' => ['nullable', 'integer', 'min:1']]);

        return $this->run($request, fn () => $invoices->issueBuyer(
            $venueBooking->id,
            $actors->resolveForRequest($request),
            $data['template_id'] ?? null,
        ), 'Счёт покупателю выставлен.');
    }

    public function claim(
        Request $request, VenueBooking $venueBooking, VenueRentalInvoice $invoice,
        CurrentActorResolver $actors, VenueRentalInvoiceManager $invoices,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->run($request, fn () => $invoices->claim(
            $venueBooking->id, $invoice->id, $actors->resolveForRequest($request), $data['note'] ?? null,
        ), 'Заявление об оплате отправлено.');
    }

    public function settle(
        Request $request, VenueBooking $venueBooking, VenueRentalInvoice $invoice,
        CurrentActorResolver $actors, VenueRentalInvoiceManager $invoices,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:received,postpay,self_attested'],
            'note' => ['required', 'string', 'max:2000'],
            'method' => ['nullable', 'in:bank_transfer,cash,other'],
        ]);

        return $this->run($request, fn () => $invoices->settle(
            $venueBooking->id,
            $invoice->id,
            $actors->resolveForRequest($request),
            $data['decision'],
            $data['note'],
            $data['method'] ?? 'other',
        ), 'Решение по счёту сохранено.');
    }

    /** @param callable(): VenueRentalInvoice $action */
    private function run(Request $request, callable $action, string $message): JsonResponse|RedirectResponse
    {
        try {
            $invoice = $action();
        } catch (VenueBookingTransitionException $exception) {
            return $request->expectsJson()
                ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409)
                : back()->withInput()->with('error', $exception->getMessage());
        }
        return $request->expectsJson()
            ? response()->json([
                'invoice_id' => $invoice->public_id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'kind' => $invoice->kind,
                'amount_minor' => $invoice->amount_minor,
                'currency' => $invoice->currency,
            ])
            : back()->with('status', $message);
    }
}
