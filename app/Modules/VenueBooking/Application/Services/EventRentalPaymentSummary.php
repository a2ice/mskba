<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonInterface;

/** Read-only payer projection for the public event shell. */
final class EventRentalPaymentSummary
{
    /** @return array{state:string,label:string,due_at:CarbonInterface|null}|null */
    public function forViewer(?VenueBooking $booking, ?User $viewer): ?array
    {
        if ($booking === null || $viewer === null || $viewer->isBlocked() || $viewer->trashed()
            || $booking->requester_user_id === null
            || ! $viewer->isSameIdentity((int) $booking->requester_user_id)) {
            return null;
        }

        if ((bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
            $invoice = $booking->invoices()->where('kind', 'buyer')
                ->first(['status', 'payer_user_id', 'due_at']);
            if ($invoice !== null && ($invoice->payer_user_id === null
                || ! $viewer->isSameIdentity((int) $invoice->payer_user_id))) {
                return null;
            }
            $state = $invoice?->status ?? 'awaiting_invoice';
            $label = match ($state) {
                'awaiting_invoice', 'draft' => 'Счёт ещё не выставлен',
                'issued' => 'Счёт выставлен',
                'claimed' => 'Ожидает подтверждения оплаты',
                'paid' => 'Счёт оплачен',
                'postpay' => 'Постоплата согласована',
                'void' => 'Счёт отменён',
                default => 'Статус счёта недоступен',
            };

            return ['state' => $state, 'label' => $label,
                'due_at' => $state === 'issued' ? $invoice?->due_at : null];
        }

        $state = $booking->payment_state;
        if ($state === null) {
            return null;
        }

        $label = match ($state) {
            VenueBookingPaymentState::NOT_REQUIRED => 'Оплата не требуется',
            VenueBookingPaymentState::NOT_STARTED, VenueBookingPaymentState::READY => 'Ожидается оплата',
            VenueBookingPaymentState::WINDOW_OPEN => 'Ожидается оплата',
            VenueBookingPaymentState::CLAIMED => 'Ожидает подтверждения оплаты',
            VenueBookingPaymentState::CONFIRMED => 'Оплата подтверждена',
            VenueBookingPaymentState::REJECTED => 'Оплата отклонена',
            VenueBookingPaymentState::DISPUTED => 'Оплата оспаривается',
            VenueBookingPaymentState::EXPIRED => 'Срок оплаты истёк',
        };

        return ['state' => $state->value, 'label' => $label,
            'due_at' => $state === VenueBookingPaymentState::WINDOW_OPEN ? $booking->payment_window_expires_at : null];
    }
}
