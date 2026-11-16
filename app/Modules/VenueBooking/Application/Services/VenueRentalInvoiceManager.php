<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoice;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoiceTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class VenueRentalInvoiceManager
{
    public function __construct(
        private LockedVenueBooking $locked,
        private VenueBookingAuthorization $authorization,
        private VenueExternalHoldManager $externalHolds,
    ) {}

    public function applicableTemplates(VenueBooking $booking, ?Actor $actor = null)
    {
        return VenueRentalInvoiceTemplate::query()->where('enabled', true)
            ->where(function ($query) use ($booking, $actor): void {
                $query->where('scope', 'global')
                    ->orWhere(function ($query) use ($booking): void {
                        $query->where('scope', 'venue')->where('venue_id', $booking->venue_id);
                    });
                if ($actor?->user_id !== null) {
                    $query->orWhere(function ($query) use ($booking, $actor): void {
                        $query->where('scope', 'agent')->where('venue_id', $booking->venue_id)
                            ->where('owner_user_id', $actor->user_id);
                    });
                }
            })->orderBy('priority')->orderBy('id')->get();
    }

    private function assertEnabled(VenueBooking $booking): void
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
            throw new VenueBookingTransitionException('Счета для этой заявки не включены.', 'INVOICES_DISABLED');
        }
    }

    private function agentUserId(VenueBooking $booking): ?int
    {
        $policy = VenueCommissionPolicy::query()->where('venue_id', $booking->venue_id)->first();
        $agent = $policy?->selectedAgent;
        if ($agent === null || ! $agent->is_active || $agent->user === null
            || ! $agent->user->isConfirmed() || $agent->user->isBlocked()) {
            return null;
        }
        return (int) $agent->user_id;
    }

    private function authorized(Actor $actor, VenueBooking $booking): void
    {
        $this->authorization->assertCanConfirmPayment($actor, $booking->venue, $booking);
    }

    /** Called inside a locked booking transaction when the slot becomes HELD. */
    public function autoIssueBuyerWithinLock(VenueBooking $booking, Actor $actor): ?VenueRentalInvoice
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
            return null;
        }
        $template = $this->applicableTemplates($booking, $actor)->first(
            fn (VenueRentalInvoiceTemplate $value): bool => $value->auto_send,
        );
        if ($template === null) {
            return null;
        }
        return $this->issueBuyerWithinLock($booking, $actor, $template);
    }

    public function issueBuyer(int $bookingId, Actor $actor, ?int $templateId = null): VenueRentalInvoice
    {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use ($actor, $templateId): VenueRentalInvoice {
            $this->assertEnabled($booking);
            $this->authorized($actor, $booking);
            $template = $templateId === null ? null
                : $this->applicableTemplates($booking, $actor)->firstWhere('id', $templateId);
            if ($templateId !== null && $template === null) {
                throw new VenueBookingTransitionException('Недоступный шаблон счёта.', 'INVOICE_TEMPLATE_FORBIDDEN');
            }

            return $this->issueBuyerWithinLock($booking, $actor, $template);
        });
    }

    private function issueBuyerWithinLock(
        VenueBooking $booking, Actor $actor, ?VenueRentalInvoiceTemplate $template,
    ): VenueRentalInvoice {
        $this->assertEnabled($booking);
        if ($booking->status !== VenueBookingStatusEnum::HELD
            || $booking->effective_protection_until === null
            || ! now()->lessThan($booking->effective_protection_until)) {
            throw new VenueBookingTransitionException('Счёт можно выставить только при действующем холде.', 'HOLD_REQUIRED');
        }
        $this->externalHolds->assertActive($booking);
        if ($existing = VenueRentalInvoice::query()
            ->where('venue_booking_id', $booking->id)->where('kind', 'buyer')->first()) {
            return $existing;
        }

        $deadline = CarbonImmutable::instance($booking->effective_protection_until);
        $duration = $template?->due_minutes ?? (int) data_get($booking->quote_snapshot, 'policy.payment_window_minutes', 30);
        $due = CarbonImmutable::now()->addMinutes(max(1, (int) $duration));
        if ($due->greaterThan($deadline)) {
            $due = $deadline;
        }
        $agentId = $this->agentUserId($booking);
        $invoice = $this->create(
            $booking, 'buyer', $agentId === null ? 'venue' : 'agent', $agentId,
            $booking->requester_user_id, $actor, $template,
            (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', -1),
            $due,
        );
        $booking->forceFill([
            'payment_state' => VenueBookingPaymentState::WINDOW_OPEN,
            'payment_window_expires_at' => $due,
            'optimistic_version' => $booking->optimistic_version + 1,
        ])->save();

        return $invoice;
    }

    private function create(
        VenueBooking $booking, string $kind, string $issuerKind, ?int $issuerUserId,
        ?int $payerUserId, Actor $actor, ?VenueRentalInvoiceTemplate $template,
        int $amount, CarbonImmutable $due,
    ): VenueRentalInvoice {
        $currency = (string) data_get($booking->quote_snapshot, 'pricing.currency', '');
        if ($amount < 0 || $amount > 1000000000000 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VenueBookingTransitionException('Некорректная сумма счёта.', 'INVOICE_INVALID_AMOUNT');
        }
        $invoice = VenueRentalInvoice::query()->create([
            'public_id' => (string) Str::uuid(),
            'number' => 'INV-'.strtoupper(Str::random(12)),
            'venue_booking_id' => $booking->id,
            'template_id' => $template?->id,
            'kind' => $kind,
            'issuer_kind' => $issuerKind,
            'issuer_user_id' => $issuerUserId,
            'payer_user_id' => $payerUserId,
            'issued_by_actor_id' => $actor->id,
            'status' => $amount === 0 ? 'paid' : 'issued',
            'currency' => $currency,
            'amount_minor' => $amount,
            'title' => $template?->title ?? ($kind === 'buyer' ? 'Аренда зала' : 'Расчёт за аренду по агентскому контракту'),
            'description' => $template?->description,
            'payment_methods' => $template?->payment_methods ?? ['bank_transfer', 'cash', 'other'],
            'payment_instructions' => $template?->payment_instructions,
            'issued_at' => now(),
            'due_at' => $due,
            'settled_at' => $amount === 0 ? now() : null,
            'quote_snapshot' => $booking->quote_snapshot,
        ]);
        $this->event($invoice, $actor, 'issue', null, $amount === 0 ? 'paid' : 'issued');

        return $invoice;
    }

    public function claim(int $bookingId, int $invoiceId, Actor $actor, ?string $note): VenueRentalInvoice
    {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use ($invoiceId, $actor, $note): VenueRentalInvoice {
            $invoice = $this->invoice($booking, $invoiceId);
            if ($invoice->kind !== 'buyer' || $actor->user_id !== $booking->requester_user_id) {
                throw new VenueBookingTransitionException('Сообщить об оплате может только заказчик.', 'BOOKING_FORBIDDEN');
            }
            if ($invoice->status === 'claimed') return $invoice;
            if ($invoice->status !== 'issued' || ! now()->lessThan($invoice->due_at)) {
                throw new VenueBookingTransitionException('Счёт больше не ожидает оплату.', 'INVOICE_EXPIRED');
            }
            $invoice->update(['status' => 'claimed']);
            $this->event($invoice, $actor, 'claim', 'issued', 'claimed', $note);

            return $invoice;
        });
    }

    public function settle(
        int $bookingId, int $invoiceId, Actor $actor, string $decision,
        string $note, string $method = 'other',
    ): VenueRentalInvoice {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use (
            $invoiceId, $actor, $decision, $note, $method,
        ): VenueRentalInvoice {
            $this->assertEnabled($booking);
            $invoice = $this->invoice($booking, $invoiceId);
            $this->authorized($actor, $booking);
            if (in_array($invoice->status, ['paid', 'postpay'], true)) return $invoice;
            if (! in_array($invoice->status, ['issued', 'claimed'], true)) {
                throw new VenueBookingTransitionException('Счёт нельзя закрыть.', 'INVOICE_ALREADY_CLOSED');
            }
            if (! in_array($decision, ['received', 'postpay', 'self_attested'], true) || trim($note) === '') {
                throw new VenueBookingTransitionException('Укажите решение и основание.', 'INVOICE_INVALID_DECISION');
            }
            if ($booking->status !== VenueBookingStatusEnum::HELD
                || $booking->effective_protection_until === null
                || ! now()->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingTransitionException('Резерв времени уже истёк.', 'HOLD_EXPIRED');
            }
            $this->externalHolds->assertActive($booking);
            $agentId = $this->agentUserId($booking);
            if ($invoice->kind === 'buyer') {
                if ($decision === 'self_attested') {
                    throw new VenueBookingTransitionException('Для счёта покупателя допустима оплата или постоплата.', 'INVOICE_INVALID_DECISION');
                }
                if ($decision === 'postpay'
                    && ! (bool) data_get($booking->quote_snapshot, 'policy.buyer_postpay_allowed', false)) {
                    throw new VenueBookingTransitionException('Постоплата покупателя не разрешена.', 'POSTPAY_FORBIDDEN');
                }
            } else {
                if ($agentId === null) {
                    throw new VenueBookingTransitionException('Агент не назначен.', 'AGENT_NOT_FOUND');
                }
                if ($decision === 'postpay'
                    && ! (bool) data_get($booking->quote_snapshot, 'policy.agent_provider_postpay_allowed', false)) {
                    throw new VenueBookingTransitionException('Постоплата площадке не разрешена.', 'POSTPAY_FORBIDDEN');
                }
                if ($actor->user_id === $agentId && $decision !== 'postpay'
                    && (! (bool) data_get($booking->quote_snapshot, 'policy.agent_provider_self_attestation_allowed', false)
                        || $decision !== 'self_attested')) {
                    throw new VenueBookingTransitionException('Агент не может подтвердить получение денег площадкой.', 'BOOKING_FORBIDDEN');
                }
            }
            $newStatus = $decision === 'postpay' ? 'postpay' : 'paid';
            $old = $invoice->status;
            $invoice->update([
                'status' => $newStatus, 'settlement_basis' => $decision,
                'settlement_note' => trim($note), 'reviewed_by_actor_id' => $actor->id,
                'settled_at' => now(),
            ]);
            if ($decision !== 'postpay') {
                $invoice->payments()->create([
                    'actor_id' => $actor->id, 'amount_minor' => $invoice->amount_minor,
                    'method' => $method, 'note' => trim($note),
                    'verification' => $decision === 'self_attested' ? 'self_attested' : 'confirmed',
                    'recorded_at' => now(),
                ]);
            }
            $this->event($invoice, $actor, $decision, $old, $newStatus, $note);

            if ($invoice->kind === 'buyer') {
                $booking->forceFill([
                    'payment_state' => VenueBookingPaymentState::CONFIRMED,
                    'optimistic_version' => $booking->optimistic_version + 1,
                ])->save();
                if ($agentId !== null) $this->issueProviderWithinLock($booking, $agentId, $actor);
            }

            return $invoice->fresh();
        });
    }

    private function issueProviderWithinLock(VenueBooking $booking, int $agentId, Actor $actor): void
    {
        if (VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)->where('kind', 'provider')->exists()) {
            return;
        }
        $pricing = data_get($booking->quote_snapshot, 'pricing', []);
        $amount = (int) data_get($pricing, 'commissions.provider_amount_minor', -1);
        $discount = data_get($pricing, 'personal_discount');
        if (is_array($discount)) {
            $discountMinor = max(0, (int) ($discount['original_amount_minor'] ?? 0) - (int) ($discount['final_amount_minor'] ?? 0));
            if ($discountMinor > $amount) {
                throw new VenueBookingTransitionException('Скидка превышает долю площадки: требуется уточнение договора.', 'INVOICE_SPLIT_UNDEFINED');
            }
            $amount -= $discountMinor;
        }
        $due = CarbonImmutable::now()->addMinutes(
            (bool) data_get($booking->quote_snapshot, 'policy.agent_provider_postpay_allowed', false) ? 10080 : 60
        );
        $this->create($booking, 'provider', 'venue', null, $agentId, $actor, null, $amount, $due);
    }

    public function assertCanConfirm(VenueBooking $booking): void
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) return;
        $this->externalHolds->assertActive($booking);
        $buyer = VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)->where('kind', 'buyer')->first();
        if ($buyer === null || ! in_array($buyer->status, ['paid', 'postpay'], true)) {
            throw new VenueBookingTransitionException('Счёт покупателю не урегулирован.', 'BUYER_INVOICE_OPEN');
        }
        if ($this->agentUserId($booking) !== null) {
            $provider = VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)->where('kind', 'provider')->first();
            if ($provider === null || ! in_array($provider->status, ['paid', 'postpay'], true)) {
                throw new VenueBookingTransitionException('Расчёт с площадкой не урегулирован.', 'PROVIDER_INVOICE_OPEN');
            }
        }
    }

    private function invoice(VenueBooking $booking, int $id): VenueRentalInvoice
    {
        return VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)
            ->lockForUpdate()->findOrFail($id);
    }

    private function event(VenueRentalInvoice $invoice, Actor $actor, string $action, ?string $old, string $new, ?string $note = null): void
    {
        $invoice->events()->create([
            'actor_id' => $actor->id, 'action' => $action,
            'old_status' => $old, 'new_status' => $new, 'note' => $note,
        ]);
    }
}
