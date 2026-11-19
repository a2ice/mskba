<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoice;

final readonly class VenueRentalInvoiceCard
{
    public function __construct(private VenueRentalInvoiceManager $invoices) {}

    /** @return array<string, mixed>|null */
    public function forMessage(VenueBookingMessage $message, Actor $viewer): ?array
    {
        if ($message->type !== 'invoice' || ! $message->rental_invoice_id) {
            return null;
        }
        $invoice = $message->rentalInvoice;
        if (! $invoice instanceof VenueRentalInvoice || $invoice->kind !== 'buyer') {
            return null;
        }
        $booking = $invoice->booking;
        if ($booking === null) {
            return null;
        }
        $isPayer = (int) $invoice->payer_user_id === (int) $viewer->user_id;
        $canConfirm = $this->invoices->canReceiveBuyer($booking, $viewer);
        $canSeeDetails = $isPayer || $canConfirm;
        $methods = [
            'sbp' => 'СБП / перевод по номеру карты',
            'bank_transfer' => 'Банковский перевод',
            'cash' => 'Наличные',
            'other' => 'Другой способ',
        ];
        $details = $canSeeDetails ? ($invoice->payment_details ?? []) : [];
        $copy = $details === [] ? '' : implode("\n", array_filter([
            'Банк: '.($details['bank'] ?? ''),
            empty($details['phone']) ? null : 'Телефон: '.$details['phone'],
            empty($details['card']) ? null : 'Карта: '.$details['card'],
            empty($details['purpose']) ? null : 'Комментарий: '.$details['purpose'],
        ]));
        return [
            'number' => $invoice->number,
            'title' => $invoice->title,
            'amount' => number_format((int) $invoice->amount_minor / 100, 2, ',', ' '),
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'status_label' => match ($invoice->status) {
                'issued' => 'Ожидает оплаты',
                'claimed' => 'Оплата заявлена, ожидает проверки',
                'paid' => 'Оплачен',
                'postpay' => 'Согласована постоплата',
                'void' => 'Отменён',
                default => $invoice->status,
            },
            'methods' => array_values(array_map(static fn ($method) => $methods[$method] ?? $method, $invoice->payment_methods ?? [])),
            'instructions' => $canSeeDetails ? $invoice->payment_instructions : null,
            'details' => $details,
            'copy_text' => $copy,
            'reference' => $canSeeDetails ? $invoice->payment_reference : null,
            'due_at' => $invoice->due_at?->setTimezone('Europe/Moscow')->format('d.m.Y H:i').' МСК',
            'claim_url' => $isPayer && $invoice->status === 'issued' && $invoice->due_at?->isFuture()
                ? route('account.venue-bookings.invoices.claim', [$booking, $invoice]) : null,
            'reference_required' => in_array('sbp', $invoice->payment_methods ?? [], true),
            'settle_url' => $canConfirm && in_array($invoice->status, ['issued', 'claimed', 'postpay'], true)
                && ($invoice->status === 'postpay' || $booking->effective_protection_until?->isFuture())
                ? route('account.venue-bookings.invoices.settle', [$booking, $invoice]) : null,
            'settle_method' => $invoice->payment_methods[0] ?? 'other',
            'confirmation_hint' => $canSeeDetails ? ($details['confirmation_hint'] ?? null) : null,
            'booking_url' => route('account.venue-bookings.show', $booking),
        ];
    }
}
