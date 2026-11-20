<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoice;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoiceTemplate;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPaymentProfile;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Domain\Events\VenueBookingMessageSent;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingMessageSentBroadcast;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Every payment obligation to a venue passes through MSKBA.
 *
 * With agent: buyer -> agent -> portal -> venue.
 * Without agent: buyer -> portal -> venue.
 *
 * This ledger uses the immutable quote's existing commission split. It does not
 * pretend the legacy agent surcharge is a wholesale/resale contract: that
 * separate commercial contract requires its own future quote calculation.
 */
final readonly class VenueRentalInvoiceManager
{
    public function __construct(
        private LockedVenueBooking $locked,
        private VenueBookingAuthorization $authorization,
        private VenueBookingBackofficeAccess $backoffice,
        private VenueExternalHoldManager $externalHolds,
    ) {}

    public function applicableTemplates(VenueBooking $booking, ?Actor $actor = null)
    {
        return VenueRentalInvoiceTemplate::query()
            ->where('enabled', true)
            ->where(function ($query) use ($booking, $actor): void {
                $query->where('scope', 'global')
                    ->orWhere(fn ($venue) => $venue->where('scope', 'venue')
                        ->where('venue_id', $booking->venue_id));
                if ($actor?->user_id !== null) {
                    $query->orWhere(fn ($agent) => $agent->where('scope', 'agent')
                        ->where('venue_id', $booking->venue_id)
                        ->where('owner_user_id', $actor->user_id));
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
        // Financial responsibility is frozen with the quote, not today's agent assignment.
        $id = data_get($booking->quote_snapshot, 'pricing.commissions.agent.user_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{buyer:int, agent_portal:int|null, portal_venue:int, portal_fee:int, agent_fee:int} */
    public function amounts(VenueBooking $booking): array
    {
        $pricing = data_get($booking->quote_snapshot, 'pricing', []);
        $commission = data_get($pricing, 'commissions', []);
        $buyer = (int) data_get($pricing, 'amount_minor', -1);
        $provider = (int) data_get($commission, 'provider_amount_minor', -1);
        $portalFee = (int) data_get($commission, 'portal_fee_minor', -1);
        $agentFee = (int) data_get($commission, 'agent_fee_minor', -1);
        $original = (int) data_get($pricing, 'personal_discount.original_amount_minor', $buyer);
        $final = (int) data_get($pricing, 'personal_discount.final_amount_minor', $buyer);
        $discount = $original - $final;

        if (min($buyer, $provider, $portalFee, $agentFee, $discount) < 0
            || $discount > $provider
            || $buyer !== $provider + $portalFee + $agentFee - $discount
            || ($this->agentUserId($booking) === null && $agentFee !== 0)) {
            throw new VenueBookingTransitionException(
                'Суммы расчётов не согласованы со снимком цены; требуется проверка MSKBA.',
                'INVOICE_SPLIT_UNDEFINED',
            );
        }
        return [
            'buyer' => $buyer,
            'agent_portal' => $this->agentUserId($booking) === null ? null : $buyer - $agentFee,
            'portal_venue' => $provider - $discount,
            'portal_fee' => $portalFee,
            'agent_fee' => $agentFee,
        ];
    }

    private function assertPortalFinance(Actor $actor): void
    {
        if (! $this->backoffice->canManage($actor->user)) {
            throw new VenueBookingTransitionException(
                'Подтверждать расчёты MSKBA может только уполномоченный администратор портала.',
                'BOOKING_FORBIDDEN',
            );
        }
    }

    private function assertBuyerReceiptActor(Actor $actor, VenueBooking $booking): void
    {
        $agentId = $this->agentUserId($booking);
        if ($agentId === null) {
            $this->assertPortalFinance($actor);

            return;
        }
        if ($actor->user_id !== $agentId) {
            throw new VenueBookingTransitionException(
                'Подтвердить получение денег покупателя может назначенный агент.',
                'BOOKING_FORBIDDEN',
            );
        }
        $this->authorization->assertCanConfirmPayment($actor, $booking->venue, $booking);
    }

    public function canReceiveBuyer(VenueBooking $booking, Actor $actor): bool
    {
        try {
            $this->assertBuyerReceiptActor($actor, $booking);

            return true;
        } catch (VenueBookingTransitionException) {
            return false;
        }
    }

    public function canManagePortal(Actor $actor): bool
    {
        return $this->backoffice->canManage($actor->user);
    }

    /** Called inside the booking's locked transaction, after the slot becomes HELD. */
    public function autoIssueBuyerWithinLock(VenueBooking $booking, Actor $actor): ?VenueRentalInvoice
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)
            || ! $this->canReceiveBuyer($booking, $actor)) {
            return null;
        }
        $template = $this->applicableTemplates($booking, $actor)->first(
            fn (VenueRentalInvoiceTemplate $item): bool => $item->auto_send,
        );

        if ($template === null) {
            return null;
        }
        if ($template->payment_methods === ['sbp']) {
            // An automatic invoice must never send an empty SBP destination.
            $profile = VenueRentalPaymentProfile::query()->where('owner_user_id', $actor->user_id)
                ->where('venue_id', $booking->venue_id)->latest('id')->first();
            if ($profile === null) {
                return null;
            }
            return $this->issueBuyerWithinLock($booking, $actor, $template, [
                'payment_method' => 'sbp', 'payment_profile_id' => $profile->id,
            ]);
        }

        return $this->issueBuyerWithinLock($booking, $actor, $template);
    }

    public function issueBuyer(int $bookingId, Actor $actor, ?int $templateId = null, array $paymentOptions = []): VenueRentalInvoice
    {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use ($actor, $templateId, $paymentOptions): VenueRentalInvoice {
            $this->assertEnabled($booking);
            $this->assertBuyerReceiptActor($actor, $booking);
            $template = $templateId === null ? null
                : $this->applicableTemplates($booking, $actor)->firstWhere('id', $templateId);
            if ($templateId !== null && $template === null) {
                throw new VenueBookingTransitionException('Недоступный шаблон счёта.', 'INVOICE_TEMPLATE_FORBIDDEN');
            }

            return $this->issueBuyerWithinLock($booking, $actor, $template, $paymentOptions);
        });
    }

    private function issueBuyerWithinLock(
        VenueBooking $booking, Actor $actor, ?VenueRentalInvoiceTemplate $template, array $paymentOptions = [],
    ): VenueRentalInvoice {
        $this->assertEnabled($booking);
        $this->assertActiveBookingHold($booking);
        if ($existing = $this->findKind($booking, 'buyer')) {
            return $existing;
        }

        $distribution = $this->amounts($booking);
        $deadline = CarbonImmutable::instance($booking->effective_protection_until);
        $duration = (int) ($template?->due_minutes
            ?? data_get($booking->quote_snapshot, 'policy.payment_window_minutes', 30));
        $due = CarbonImmutable::now()->addMinutes(max(1, $duration));
        if ($due->greaterThan($deadline)) {
            $due = $deadline;
        }
        if (($paymentOptions['payment_method'] ?? null) === null && $template?->payment_methods === ['sbp']) {
            $paymentOptions['payment_method'] = 'sbp';
            if (empty($paymentOptions['payment_profile_id']) && empty($paymentOptions['sbp_bank'])) {
                $profile = VenueRentalPaymentProfile::query()->where('owner_user_id', $actor->user_id)
                    ->where('venue_id', $booking->venue_id)->latest('id')->first();
                if ($profile !== null) {
                    $paymentOptions['payment_profile_id'] = $profile->id;
                }
            }
        }
        [$selectedMethod, $paymentDetails] = $this->resolvePaymentOptions($booking, $actor, $paymentOptions);
        $agentId = $this->agentUserId($booking);
        $invoice = $this->create(
            $booking, 'buyer', $agentId === null ? 'portal' : 'agent', 'buyer',
            $agentId, $booking->requester_user_id, $actor, $template, $distribution['buyer'], $due,
            'invoice', $selectedMethod === null ? null : [$selectedMethod], $paymentDetails,
        );
        $booking->forceFill([
            'payment_state' => VenueBookingPaymentState::WINDOW_OPEN,
            'payment_window_expires_at' => $due,
            'optimistic_version' => $booking->optimistic_version + 1,
        ])->save();

        return $invoice;
    }

    private function create(
        VenueBooking $booking, string $kind, string $issuerKind, string $payerKind,
        ?int $issuerUserId, ?int $payerUserId, Actor $actor,
        ?VenueRentalInvoiceTemplate $template, int $amount, CarbonImmutable $due, string $documentKind, ?array $paymentMethods = null, ?array $paymentDetails = null,
    ): VenueRentalInvoice {
        $currency = (string) data_get($booking->quote_snapshot, 'pricing.currency', '');
        if ($amount < 0 || $amount > 1000000000000 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VenueBookingTransitionException('Некорректная сумма счёта.', 'INVOICE_INVALID_AMOUNT');
        }
        $titles = [
            'buyer' => 'Аренда зала',
            'agent_portal' => 'Расчёт агента с MSKBA',
            'portal_venue' => 'Обязательство MSKBA перед площадкой',
        ];
        $invoice = VenueRentalInvoice::query()->create([
            'public_id' => (string) Str::uuid(),
            'number' => 'INV-'.strtoupper(Str::random(12)),
            'venue_booking_id' => $booking->id,
            'template_id' => $template?->id,
            'kind' => $kind,
            'issuer_kind' => $issuerKind,
            'payer_kind' => $payerKind,
            'document_kind' => $documentKind,
            'issuer_user_id' => $issuerUserId,
            'payer_user_id' => $payerUserId,
            'issued_by_actor_id' => $actor->id,
            'status' => $amount === 0 ? 'paid' : 'issued',
            'currency' => $currency,
            'amount_minor' => $amount,
            'title' => $template?->title ?? $titles[$kind],
            'description' => $template?->description,
            'payment_methods' => $paymentMethods ?? $template?->payment_methods ?? ['bank_transfer', 'cash', 'other'],
            'payment_details' => $paymentDetails,
            'payment_instructions' => $template?->payment_instructions,
            'issued_at' => now(),
            'due_at' => $due,
            'settled_at' => $amount === 0 ? now() : null,
            'quote_snapshot' => $booking->quote_snapshot,
        ]);
        $this->event($invoice, $actor, 'issue', null, $amount === 0 ? 'paid' : 'issued');
        if ($kind === 'buyer') {
            $this->announceBuyerInvoice($booking, $actor, $invoice);
        }

        return $invoice;
    }

    /** Only the customer-facing invoice is announced in the shared conversation. */
    private function announceBuyerInvoice(VenueBooking $booking, Actor $actor, VenueRentalInvoice $invoice): void
    {
        if (! config('features.venue_rental.conversations', false)) {
            return;
        }
        $conversation = VenueBookingConversation::query()->firstOrCreate(
            $booking->conversationLookup(),
            ['public_id' => (string) Str::uuid()],
        );
        $minor = (int) $invoice->amount_minor;
        $amount = number_format($minor / 100, 2, ',', ' ');
        $message = VenueBookingMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'author_actor_id' => $actor->id,
            'client_id' => (string) Str::uuid(),
            'type' => 'invoice',
            'rental_invoice_id' => $invoice->id,
            'body' => "Выставлен счёт {$invoice->number} на {$amount} {$invoice->currency}. "
                ."Срок до {$invoice->due_at->setTimezone('Europe/Moscow')->format('d.m.Y H:i')} (МСК). "
                .'Оплата и её статус доступны в карточке заявки.',
        ]);
        DB::afterCommit(static function () use ($booking, $conversation, $message): void {
            event(new VenueBookingMessageSent($booking->id, $conversation->id, $message->id));
            broadcast(new VenueBookingMessageSentBroadcast(
                $booking->public_id, $conversation->public_id, $message->public_id,
            ))->toOthers();
        });
    }

    /** @return array{0:?string,1:?array} */
    private function resolvePaymentOptions(VenueBooking $booking, Actor $actor, array $options): array
    {
        $method = $options['payment_method'] ?? null;
        if (! in_array($method, [null, 'bank_transfer', 'sbp', 'cash', 'other'], true)) {
            throw new VenueBookingTransitionException('Неизвестный способ оплаты.', 'INVOICE_INVALID_METHOD');
        }
        if ($method !== 'sbp') {
            return [$method, null];
        }
        $profileId = $options['payment_profile_id'] ?? null;
        if ($profileId !== null) {
            $profile = VenueRentalPaymentProfile::query()
                ->where('owner_user_id', $actor->user_id)
                ->where('venue_id', $booking->venue_id)->find($profileId);
            if ($profile === null) {
                throw new VenueBookingTransitionException('Реквизиты недоступны.', 'INVOICE_PROFILE_FORBIDDEN');
            }
            return ['sbp', $profile->details];
        }
        $card = preg_replace('/\\D+/', '', (string) ($options['sbp_card'] ?? ''));
        $phone = trim((string) ($options['sbp_phone'] ?? ''));
        $bank = trim((string) ($options['sbp_bank'] ?? ''));
        if ($bank === '' || ($phone === '' && $card === '')
            || ($card !== '' && (strlen($card) < 16 || strlen($card) > 19))
            || ($phone !== '' && preg_match('/^\\+?[0-9 ()-]{9,25}$/', $phone) !== 1)) {
            throw new VenueBookingTransitionException('Укажите банк и корректный телефон или карту получателя.', 'INVOICE_SBP_INVALID');
        }
        $details = [
            'bank' => $bank, 'phone' => $phone, 'card' => $card,
            'purpose' => trim((string) ($options['transfer_purpose'] ?? '')),
            'confirmation_hint' => trim((string) ($options['confirmation_hint'] ?? '')),
        ];
        if (! empty($options['save_profile'])) {
            VenueRentalPaymentProfile::query()->create([
                'owner_user_id' => $actor->user_id,
                'venue_id' => $booking->venue_id,
                'label' => mb_substr($bank.' · '.($phone ?: 'карта ••••'.substr($card, -4)), 0, 120),
                'details' => $details,
            ]);
        }
        return ['sbp', $details];
    }

    private function announceInvoiceClaim(VenueBooking $booking, Actor $actor, VenueRentalInvoice $invoice): void
    {
        if (! config('features.venue_rental.conversations', false)) {
            return;
        }
        $conversation = VenueBookingConversation::query()->where($booking->conversationLookup())->first();
        if ($conversation === null) {
            return;
        }
        $message = VenueBookingMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'author_actor_id' => $actor->id,
            'client_id' => (string) Str::uuid(),
            'type' => 'system',
            'body' => 'Покупатель сообщил об оплате счёта '.$invoice->number
                .'. Реквизиты операции переданы получателю. Получение денег ещё не подтверждено.',
        ]);
        DB::afterCommit(static function () use ($booking, $conversation, $message): void {
            event(new VenueBookingMessageSent($booking->id, $conversation->id, $message->id));
            broadcast(new VenueBookingMessageSentBroadcast(
                $booking->public_id, $conversation->public_id, $message->public_id,
            ))->toOthers();
        });
    }

    /** Buyer or agent reports transfer. This is not evidence that funds were received. */
    public function claim(int $bookingId, int $invoiceId, Actor $actor, ?string $note, ?string $reference = null): VenueRentalInvoice
    {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use ($invoiceId, $actor, $note, $reference): VenueRentalInvoice {
            $this->assertEnabled($booking);
            $invoice = $this->invoice($booking, $invoiceId);
            $canClaim = ($invoice->kind === 'buyer' && $actor->user_id === $booking->requester_user_id)
                || ($invoice->kind === 'agent_portal' && $actor->user_id === $this->agentUserId($booking));
            if (! $canClaim) {
                throw new VenueBookingTransitionException('Заявление об оплате недоступно.', 'BOOKING_FORBIDDEN');
            }
            if ($invoice->status === 'claimed') {
                return $invoice;
            }
            if ($invoice->status !== 'issued' || ! now()->lessThan($invoice->due_at)) {
                throw new VenueBookingTransitionException('Счёт больше не ожидает оплату.', 'INVOICE_EXPIRED');
            }
            $reference = trim((string) $reference);
            if (in_array('sbp', $invoice->payment_methods ?? [], true) && $reference === '') {
                throw new VenueBookingTransitionException('Укажите номер операции перевода.', 'INVOICE_REFERENCE_REQUIRED');
            }
            $invoice->update([
                'status' => 'claimed', 'payment_reference' => $reference ?: null,
                'payment_claim_note' => trim((string) $note) ?: null,
            ]);
            $this->event($invoice, $actor, 'claim', 'issued', 'claimed',
                'Номер операции: '.($reference ?: 'не указан').'; '.trim((string) $note));
            if ($invoice->kind === 'buyer') {
                $this->announceInvoiceClaim($booking, $actor, $invoice);
            }

            return $invoice;
        });
    }

    /**
     * A decision about an invoice is distinct from actual electronic payment.
     * Postpay is NOT paid: it leaves an open debt and is accepted only when
     * the applicable contract/policy allows confirmation without settlement.
     */
    public function settle(
        int $bookingId, int $invoiceId, Actor $actor, string $decision,
        string $note, string $method = 'other',
    ): VenueRentalInvoice {
        return $this->locked->run($bookingId, function (VenueBooking $booking) use (
            $invoiceId, $actor, $decision, $note, $method,
        ): VenueRentalInvoice {
            $this->assertEnabled($booking);
            $invoice = $this->invoice($booking, $invoiceId);
            if (! in_array($decision, ['received', 'postpay', 'self_attested'], true) || trim($note) === '') {
                throw new VenueBookingTransitionException('Укажите решение и основание.', 'INVOICE_INVALID_DECISION');
            }
            if (! in_array($method, ['bank_transfer', 'sbp', 'cash', 'other'], true)) {
                throw new VenueBookingTransitionException('Некорректный способ оплаты.', 'INVOICE_INVALID_METHOD');
            }
            if ($invoice->status === 'paid') {
                return $invoice;
            }
            if (! in_array($invoice->status, ['issued', 'claimed', 'postpay'], true)) {
                throw new VenueBookingTransitionException('Счёт нельзя закрыть.', 'INVOICE_ALREADY_CLOSED');
            }

            if ($invoice->kind === 'buyer') {
                $this->assertBuyerReceiptActor($actor, $booking);
                if ($decision === 'self_attested') {
                    throw new VenueBookingTransitionException('Оплату покупателя должен подтвердить получатель.', 'BOOKING_FORBIDDEN');
                }
                if ($decision === 'postpay'
                    && ! (bool) data_get($booking->quote_snapshot, 'policy.buyer_postpay_allowed', false)) {
                    throw new VenueBookingTransitionException('Постоплата покупателя не разрешена.', 'POSTPAY_FORBIDDEN');
                }
            } elseif ($invoice->kind === 'agent_portal') {
                $agentId = $this->agentUserId($booking);
                if ($agentId === null) {
                    throw new VenueBookingTransitionException('Агент отсутствует в снимке сделки.', 'AGENT_NOT_FOUND');
                }
                if ($actor->user_id === $agentId) {
                    if ($decision !== 'self_attested'
                        || ! (bool) data_get($booking->quote_snapshot, 'policy.agent_portal_self_attestation_allowed', false)) {
                        throw new VenueBookingTransitionException('Агент не вправе подтверждать получение денег MSKBA.', 'BOOKING_FORBIDDEN');
                    }
                    $this->authorization->assertCanConfirmPayment($actor, $booking->venue, $booking);
                } else {
                    $this->assertPortalFinance($actor);
                    if ($decision === 'self_attested') {
                        throw new VenueBookingTransitionException('Для подтверждения MSKBA используйте получение оплаты.', 'INVOICE_INVALID_DECISION');
                    }
                }
                if ($decision === 'postpay'
                    && ! (bool) data_get($booking->quote_snapshot, 'policy.agent_portal_postpay_allowed', false)) {
                    throw new VenueBookingTransitionException('Постоплата агентом для MSKBA не разрешена.', 'POSTPAY_FORBIDDEN');
                }
            } elseif ($invoice->kind === 'portal_venue') {
                $this->assertPortalFinance($actor);
                if ($decision === 'self_attested') {
                    throw new VenueBookingTransitionException('Получение денег площадкой не может подтверждаться автоматически.', 'BOOKING_FORBIDDEN');
                }
                if ($decision === 'postpay'
                    && ! (bool) data_get($booking->quote_snapshot, 'policy.portal_venue_postpay_allowed', false)) {
                    throw new VenueBookingTransitionException('Постоплата MSKBA площадке не разрешена.', 'POSTPAY_FORBIDDEN');
                }
            } else {
                throw new VenueBookingTransitionException('Неизвестный тип счёта.', 'INVOICE_INVALID_KIND');
            }

            if ($invoice->status !== 'postpay') {
                $this->assertActiveBookingHold($booking);
            } elseif ($decision !== 'received') {
                throw new VenueBookingTransitionException('Задолженность можно только погасить.', 'INVOICE_INVALID_DECISION');
            }

            $old = $invoice->status;
            $newStatus = $decision === 'postpay' ? 'postpay' : 'paid';
            $invoice->update([
                'status' => $newStatus,
                'settlement_basis' => $decision,
                'settlement_note' => trim($note),
                'reviewed_by_actor_id' => $actor->id,
                'settled_at' => $newStatus === 'paid' ? now() : null,
            ]);
            if ($newStatus === 'paid' && $invoice->amount_minor > 0) {
                $invoice->payments()->create([
                    'actor_id' => $actor->id,
                    'amount_minor' => $invoice->amount_minor,
                    'method' => $method,
                    'reference' => $invoice->payment_reference,
                    'note' => trim($note),
                    'verification' => $decision === 'self_attested' ? 'self_attested' : 'confirmed',
                    'recorded_at' => now(),
                ]);
            }
            $this->event($invoice, $actor, $decision, $old, $newStatus, $note);

            if ($invoice->kind === 'buyer') {
                if ($newStatus === 'paid') {
                    $booking->forceFill([
                        'payment_state' => VenueBookingPaymentState::CONFIRMED,
                        'optimistic_version' => $booking->optimistic_version + 1,
                    ])->save();
                }
                $this->issueNextWithinLock($booking, $actor);
            } elseif ($invoice->kind === 'agent_portal') {
                $this->issueNextWithinLock($booking, $actor);
            }

            return $invoice->fresh();
        });
    }

    /** Enforce buyer -> agent -> MSKBA -> venue, or buyer -> MSKBA -> venue. */
    private function issueNextWithinLock(VenueBooking $booking, Actor $actor): void
    {
        $this->amounts($booking); // Recheck immutable allocation before recording debts.
        $agentId = $this->agentUserId($booking);
        if ($agentId !== null) {
            $buyer = $this->findKind($booking, 'buyer');
            if ($buyer !== null && in_array($buyer->status, ['paid', 'postpay'], true)
                && $this->findKind($booking, 'agent_portal') === null) {
                $this->create($booking, 'agent_portal', 'portal', 'agent',
                    null, $agentId, $actor, null,
                    $this->amounts($booking)['agent_portal'],
                    CarbonImmutable::now()->addMinutes(60), 'internal_settlement');
            }
            $middle = $this->findKind($booking, 'agent_portal');
            if ($middle === null || ! in_array($middle->status, ['paid', 'postpay'], true)) {
                return;
            }
        }
        $buyer = $this->findKind($booking, 'buyer');
        if ($buyer === null || ! in_array($buyer->status, ['paid', 'postpay'], true)
            || $this->findKind($booking, 'portal_venue') !== null) {
            return;
        }
        // Internal obligation: do not claim the venue issued an external fiscal invoice.
        $this->create($booking, 'portal_venue', 'venue', 'portal',
            null, null, $actor, null, $this->amounts($booking)['portal_venue'],
            CarbonImmutable::now()->addMinutes(60), 'internal_settlement');
    }

    public function isReady(VenueBooking $booking): bool
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
            return false;
        }
        $buyer = $this->findKind($booking, 'buyer');
        $provider = $this->findKind($booking, 'portal_venue');
        if ($buyer === null || $provider === null
            || ! in_array($buyer->status, ['paid', 'postpay'], true)
            || ! in_array($provider->status, ['paid', 'postpay'], true)) {
            return false;
        }
        if ($this->agentUserId($booking) !== null) {
            $middle = $this->findKind($booking, 'agent_portal');

            return $middle !== null && in_array($middle->status, ['paid', 'postpay'], true);
        }
        return true;
    }

    public function assertCanConfirm(VenueBooking $booking): void
    {
        if (! (bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
            return;
        }
        $this->externalHolds->assertActive($booking);
        if (! $this->isReady($booking)) {
            throw new VenueBookingTransitionException(
                'Не урегулированы все обязательства между покупателем, агентом, MSKBA и площадкой.',
                'INVOICE_CHAIN_INCOMPLETE',
            );
        }
    }

    private function assertActiveBookingHold(VenueBooking $booking): void
    {
        if ($booking->status !== VenueBookingStatusEnum::HELD
            || $booking->effective_protection_until === null
            || ! now()->lessThan($booking->effective_protection_until)) {
            throw new VenueBookingTransitionException('Слот не удерживается или срок истёк.', 'HOLD_REQUIRED');
        }
        $this->externalHolds->assertActive($booking);
    }

    private function findKind(VenueBooking $booking, string $kind): ?VenueRentalInvoice
    {
        return VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)
            ->where('kind', $kind)->first();
    }

    private function invoice(VenueBooking $booking, int $id): VenueRentalInvoice
    {
        return VenueRentalInvoice::query()->where('venue_booking_id', $booking->id)
            ->lockForUpdate()->findOrFail($id);
    }

    private function event(
        VenueRentalInvoice $invoice, Actor $actor, string $action,
        ?string $old, string $new, ?string $note = null,
    ): void {
        $invoice->events()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'old_status' => $old,
            'new_status' => $new,
            'note' => $note,
        ]);
    }
}
