@php
    $title = 'Заявка на аренду · '.$booking->venue->name;
    $timezone = $booking->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $startLocal = $booking->starts_at->setTimezone($timezone);
    $endLocal = $booking->ends_at->setTimezone($timezone);
    $durationMinutes = (int) $booking->starts_at->diffInMinutes($booking->ends_at);
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $amountMinor = (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', 0);
    $currency = (string) data_get($booking->quote_snapshot, 'pricing.currency', 'RUB');
    $statusReason = $booking->status->isTerminal() ? $booking->transitions->last()?->reason : null;
    $backUrl = $isRequester
        ? route('account.venue-bookings.index')
        : route('account.venue-bookings.inbox');
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-rental-inbox app-rental-inbox--details" aria-labelledby="rental-booking-title"
    data-venue-booking-page data-booking-id="{{ $booking->public_id }}"
    data-booking-version="{{ $booking->optimistic_version }}"
    data-booking-details-url="{{ route('account.venue-bookings.show', $booking) }}">
    <header class="app-account-overview__intro">
        <h1 id="rental-booking-title">Заявка на аренду</h1>
        <p>{{ $booking->venue->name }} · {{ $booking->court?->publicName() ?? 'Зал' }}</p>
    </header>
    @if($booking->pre_request_id)
        <p>Создано представителем по предзаявке.
            <a href="{{ route('account.venue-pre-requests.show', $booking->preRequest) }}">Переписка и согласование предложения</a></p>
    @endif

    <nav class="app-rental-inbox__tabs" aria-label="Навигация по заявке">
        <a class="button secondary" href="{{ $backUrl }}">{{ $isRequester ? 'Мои бронирования' : 'Входящие заявки' }}</a>
        <a class="button secondary" href="{{ route('venues.show', $booking->venue->routeIdentifier()) }}">К площадке</a>
    </nav>

    @if(session('status'))<p class="app-rental-inbox__notice" role="status">{{ session('status') }}</p>@endif
    @if(session('error'))<p class="app-rental-inbox__notice is-error" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())<p class="app-rental-inbox__notice is-error" role="alert">{{ $errors->first() }}</p>@endif

    <div class="panel app-rental-inbox__summary">
        <div class="app-rental-inbox__entry-head">
            <h2>Детали бронирования</h2>
            <span class="app-rental-inbox__status" data-status="{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
        </div>
        @if($statusReason)<p class="app-rental-inbox__notice">Причина: {{ $statusReason }}</p>@endif
        <dl class="app-rental-inbox__facts">
            <div><dt>Дата</dt><dd>{{ $startLocal->format('d.m.Y') }}</dd></div>
            <div><dt>Время</dt><dd>{{ $startLocal->format('H:i') }}–{{ $endLocal->format('H:i') }}</dd></div>
            <div><dt>Длительность</dt><dd>{{ $durationMinutes }} мин</dd></div>
            <div><dt>Область аренды</dt><dd>{{ $booking->scope?->label() ?? 'Весь зал' }}</dd></div>
            @unless($isRequester)
                <div><dt>Заявитель</dt><dd>{{ $booking->requester?->username ?: 'Пользователь' }}</dd></div>
            @endunless
            <div><dt>Стоимость</dt><dd>@if($special = data_get($booking->quote_snapshot, 'pricing.personal_discount'))<del>{{ $money->format($special['original_amount_minor'], $currency) }}</del> <span title="{{ $special['reason'] }}" tabindex="0">{{ $special['title'] }}</span> @endif<strong>{{ $money->format($amountMinor, $currency) }} {{ $money->currencyLabel($currency) }}</strong></dd></div>
            @if($booking->hold_expires_at)
                <div><dt>Подтвердить бронь до (холд)</dt><dd>{{ $booking->hold_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }}</dd></div>
            @endif
            <div><dt>Версия заявки</dt><dd>{{ $booking->optimistic_version }}</dd></div>
        </dl>
        <div class="app-rental-inbox__actions" aria-label="Действия с заявкой">
            @foreach(['accept' => 'Принять заявку', 'confirm' => 'Подтвердить аренду'] as $action => $label)
                @if($actions[$action]['allowed'] ?? false)
                    <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                        @csrf
                        <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <button class="button primary" type="submit">{{ $label }}</button>
                    </form>
                @endif
            @endforeach
            @foreach(['reject' => ['Отклонить', 'Подтвердить отклонение'], 'cancel' => ['Отменить', 'Подтвердить отмену']] as $action => [$label, $confirmation])
                @if($actions[$action]['allowed'] ?? false)
                    <details class="app-rental-inbox__decision">
                        <summary class="button secondary">{{ $label }}</summary>
                        <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <label for="rental-{{ $action }}-reason">Причина (необязательно)</label>
                            <textarea id="rental-{{ $action }}-reason" name="reason" maxlength="2000" rows="3"></textarea>
                            <button class="button secondary" type="submit">{{ $confirmation }}</button>
                        </form>
                    </details>
                @endif
            @endforeach
        </div>
    </div>

    @if($booking->eventIntent && !$booking->event)
        <div class="panel app-rental-inbox__summary">
            <h2>Мероприятие</h2>
            <p>«{{ data_get($booking->eventIntent->event_payload, 'title', 'Мероприятие') }}» — {{ $booking->status->isTerminal() ? 'создание не состоялось' : 'ожидает подтверждения аренды' }}.</p>
        </div>
    @elseif($booking->event)
        <div class="panel app-rental-inbox__summary">
            <h2>Мероприятие</h2>
            <a href="{{ route('events.show', $booking->event->routeIdentifier()) }}">Открыть «{{ $booking->event->title }}»</a>
        </div>
    @endif

    
    @if($usesExternalHold)
        <section class="panel app-rental-inbox__summary" aria-labelledby="external-hold-title">
            <h2 id="external-hold-title">Удержание на стороне площадки</h2>
            @if($booking->externalHold)
                <p>{{ $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED ? 'Ранее подтверждённый внешний холд' : 'Подтверждённый внешний холд' }}: {{ $booking->externalHold->method }} · до {{ $booking->externalHold->expires_at->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки).</p>
                @if($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
                    <p>Это история удержания; аренда уже подтверждена, срок холда больше не является дедлайном бронирования.</p>
                @endif
                @if($booking->externalHold->note)<p>{{ $booking->externalHold->note }}</p>@endif
            @elseif($canConfirmExternalHold)
                <p>MSKBA создаст внутренний холд только после реального подтверждения.</p>
                <form class="app-rental-inbox__fields" method="POST" action="{{ route('account.venue-bookings.external-hold.confirm', $booking) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                    <label>Способ подтверждения
                        <select name="method" required>
                            <option value="phone">Телефон</option>
                            <option value="messenger">Мессенджер</option>
                            <option value="aggregator">Агрегатор</option>
                            <option value="other">Другой способ</option>
                        </select>
                    </label>
                    <label>Внешний холд действителен до
                        <input type="datetime-local" name="expires_at" required
                            value="{{ now()->addMinutes(min((int) data_get($booking->quote_snapshot, 'policy.hold_duration_minutes', 30), 30))->setTimezone($timezone)->format('Y-m-d\TH:i') }}">
                    </label>
                    <label>Номер брони (если есть)<input name="reference" maxlength="190"></label>
                    <label>Кто и как подтвердил удержание<textarea name="note" maxlength="2000" rows="2" required></textarea></label>
                    <button class="button primary" type="submit">Подтвердить внешний холд</button>
                </form>
            @else
                <p>Ожидается подтверждение реального удержания слота.</p>
            @endif
        </section>
    @endif

    @if($usesInvoices)
        <section class="panel app-rental-inbox__summary" aria-labelledby="invoice-chain-title">
            <h2 id="invoice-chain-title">Счета и расчёты</h2>
            <p><strong>Срок удержания слота и сроки финансовых расчётов — разные вещи.</strong> Плановая дата внутреннего счёта не продлевает удержание и не разрешает подтверждение брони после его окончания.</p>
            @if($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until)
                <p><strong>Подтвердить бронирование необходимо до {{ $booking->effective_protection_until->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки).</strong> До этого момента все обязательные счета должны быть оплачены или переведены в разрешённую постоплату. После истечения холда бронь подтвердить нельзя.</p>
            @elseif($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
                <p><strong>Бронирование подтверждено.</strong> Срок удержания больше не ограничивает погашение уже согласованной постоплаты; задолженность сохраняется до фактического расчёта.</p>
            @endif
            @if($canIssueBuyerInvoice && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD
                && ! $booking->invoices->contains('kind', 'buyer'))
                <form class="app-rental-inbox__fields" method="POST" action="{{ route('account.venue-bookings.invoices.issue', $booking) }}">
                    @csrf
                    <label>Шаблон счёта
                        <select name="template_id">
                            <option value="">Стандартный счёт</option>
                            @foreach($invoiceTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="button primary" type="submit">Выставить счёт покупателю</button>
                </form>
            @endif
            @php
                $invoiceLabels = [
                    'buyer' => 'Счёт покупателю',
                    'agent_portal' => 'Агент → MSKBA',
                    'portal_venue' => 'MSKBA → площадка',
                ];
                $invoiceStatuses = [
                    'issued' => 'Выставлен', 'claimed' => 'Оплата заявлена',
                    'paid' => 'Оплачен', 'postpay' => 'Постоплата согласована',
                    'void' => 'Отменён',
                ];
                $invoiceAgentId = data_get($booking->quote_snapshot, 'pricing.commissions.agent.user_id');
            @endphp
            @forelse($visibleInvoices as $invoice)
                <article class="app-rental-inbox__summary" style="margin-top:1rem;padding:1rem;border:1px solid var(--color-border, #ddd);border-radius:8px">
                    <h3>{{ $invoiceLabels[$invoice->kind] ?? 'Счёт' }} · {{ $invoice->number }}</h3>
                    <p>{{ $invoiceStatuses[$invoice->status] ?? $invoice->status }} ·
                        <strong>{{ $money->format($invoice->amount_minor, $invoice->currency) }} {{ $money->currencyLabel($invoice->currency) }}</strong>
                    </p>
                    @if($invoice->due_at)
                        <p>{{ $invoice->kind === 'buyer' ? 'Оплатить покупателю до' : 'Плановый срок взаиморасчёта (не продлевает холд)' }}: {{ $invoice->due_at->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки)</p>
                    @endif
                    @if($invoice->status === 'postpay')
                        <p><strong>Открытая задолженность.</strong> Постоплата согласована, но средства ещё не получены. После подтверждения аренды погасить этот долг можно отдельно.</p>
                    @elseif($invoice->kind !== 'buyer' && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD)
                        <p>Для подтверждения аренды этот счёт нужно оплатить либо согласовать разрешённую постоплату до окончания холда, независимо от планового срока расчёта.</p>
                    @endif
                    @if($invoice->payment_instructions)<p>{{ $invoice->payment_instructions }}</p>@endif
                    @if($invoice->settlement_note)<p>Основание: {{ $invoice->settlement_note }}</p>@endif
                    @if($invoice->status === 'issued' && (($invoice->kind === 'buyer' && $isRequester)
                        || ($invoice->kind === 'agent_portal' && $invoiceAgentId !== null && (int) $invoiceAgentId === (int) auth()->id())))
                        <form method="POST" action="{{ route('account.venue-bookings.invoices.claim', [$booking, $invoice]) }}" class="app-rental-inbox__fields">
                            @csrf
                            <label>Комментарий об оплате<input name="note" maxlength="2000"></label>
                            <button class="button secondary" type="submit">Сообщить об оплате</button>
                        </form>
                    @endif
                    @php
                        $canSettleInvoice = ($invoice->kind === 'buyer' && $canIssueBuyerInvoice)
                            || ($invoice->kind !== 'buyer' && $canInvoicePortal);
                        $canAgentAttest = $invoice->kind === 'agent_portal'
                            && (bool) data_get($booking->quote_snapshot, 'policy.agent_portal_self_attestation_allowed', false)
                            && $invoiceAgentId !== null && (int) $invoiceAgentId === (int) auth()->id();
                    @endphp
                    @if(($canSettleInvoice || $canAgentAttest) && in_array($invoice->status, ['issued', 'claimed', 'postpay'], true))
                        <form method="POST" action="{{ route('account.venue-bookings.invoices.settle', [$booking, $invoice]) }}" class="app-rental-inbox__fields">
                            @csrf
                            <label>Решение по счёту
                                <select name="decision" required>
                                    @if($canSettleInvoice)
                                        <option value="received">{{ $invoice->kind === 'portal_venue' ? 'Подтверждаю получение средств площадкой' : 'Подтверждаю получение оплаты' }}</option>
                                        @if($invoice->status !== 'postpay')
                                            <option value="postpay">Согласована постоплата</option>
                                        @endif
                                    @endif
                                    @if($canAgentAttest)
                                        <option value="self_attested">Доверенное подтверждение агента</option>
                                    @endif
                                </select>
                            </label>
                            <label>Способ оплаты
                                <select name="method">
                                    <option value="bank_transfer">Перевод</option>
                                    <option value="cash">Наличные</option>
                                    <option value="other">Другое</option>
                                </select>
                            </label>
                            <label>Основание решения<textarea name="note" maxlength="2000" rows="2" required></textarea></label>
                            <button class="button primary" type="submit">Сохранить решение</button>
                        </form>
                    @endif
                </article>
            @empty
                <p>Счета ещё не выставлены. После подтверждения удержания будет доступно выставление счёта покупателю.</p>
            @endforelse
            @if($canIssueBuyerInvoice || $canInvoicePortal)
                <p><a href="{{ route('account.venues.invoice-templates.index', $booking->venue_id) }}">Настроить шаблоны счетов</a></p>
            @endif
        </section>
    @endif

    @if(! $usesInvoices && config('features.venue_rental.external_payment') && $canViewPayment && data_get($booking->quote_snapshot, 'policy.requires_payment'))
        <section class="panel app-rental-inbox__summary" aria-labelledby="rental-booking-payment-title">
            <h2 id="rental-booking-payment-title">Оплата</h2>
            <p>Текущее состояние: <strong>{{ $booking->payment_state->value }}</strong></p>
            @if(!$booking->paymentAttempt && $canConfirmPayment && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD)
                <form method="POST" action="{{ route('account.venue-bookings.payment.open', $booking) }}" class="app-rental-inbox__fields">
                    @csrf
                    <label>Способ оплаты
                        <select name="method" required><option value="bank_transfer">Банковский перевод</option><option value="cash">Наличные</option><option value="other">Другой</option></select>
                    </label>
                    <label>Инструкции по оплате<textarea name="payment_instructions" maxlength="4000" rows="3" required></textarea></label>
                    <button class="button primary" type="submit">Открыть оплату</button>
                </form>
            @elseif($booking->paymentAttempt)
                @php $payment = $booking->paymentAttempt; @endphp
                <p>{{ $money->format($payment->amount_minor, $payment->currency) }} {{ $money->currencyLabel($payment->currency) }} · {{ $payment->method }}</p>
                <p>{{ $payment->payment_instructions }}</p>
                <p class="app-rental-inbox__muted">Срок оплаты: {{ $payment->window_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>
                @if($isRequester && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::WINDOW_OPEN)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('account.venue-bookings.payment.claim', [$booking, $payment]) }}" class="app-rental-inbox__fields">
                        @csrf
                        <label>Номер или ссылка подтверждения<input type="text" name="reference" maxlength="255"></label>
                        <label>Комментарий<textarea name="note" maxlength="2000" rows="2"></textarea></label>
                        <label>Чек (JPG, PNG, PDF до 5 МБ)<input type="file" name="evidence_file" accept="image/jpeg,image/png,application/pdf"></label>
                        <button class="button primary" type="submit">Я оплатил</button>
                    </form>
                @endif
                @if($canConfirmPayment && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::CLAIMED)
                    <div class="app-rental-inbox__actions">
                        <form method="POST" action="{{ route('account.venue-bookings.payment.confirm', [$booking, $payment]) }}">
                            @csrf<button class="button primary" type="submit">Подтвердить оплату</button>
                        </form>
                        <form method="POST" action="{{ route('account.venue-bookings.payment.reject', [$booking, $payment]) }}">
                            @csrf<input type="hidden" name="reason" value="Подтверждение оплаты не принято.">
                            <button class="button secondary" type="submit">Отклонить оплату</button>
                        </form>
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if(config('features.venue_rental.conversations'))
        <section class="panel app-rental-inbox__summary" aria-labelledby="rental-booking-chat-title">
            <h2 id="rental-booking-chat-title">Переписка по заявке</h2>
            <p class="app-rental-inbox__muted">Сообщения не подтверждают и не отменяют аренду. Решения оформляются отдельными действиями выше.</p>
            <div class="app-rental-inbox__messages" aria-live="polite" id="booking-conversation-messages"
                data-poll-url="{{ route('account.venue-bookings.conversation.index', $booking) }}"
                data-read-url-base="{{ url('/account/venue-bookings/'.$booking->public_id.'/conversation') }}"
                data-attachment-url-base="{{ url('/account/venue-bookings/'.$booking->public_id.'/conversation/messages') }}"
                data-conversation-id="{{ $conversation?->public_id }}">
                @forelse($conversation?->messages?->sortBy('id') ?? [] as $message)
                    <article class="app-rental-inbox__message" data-message-id="{{ $message->id }}"
                        data-message-public-id="{{ $message->public_id }}">
                        <div class="app-rental-inbox__entry-head">
                            <strong>{{ $message->authorActor->user?->username ?? 'Система' }}</strong>
                            <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->setTimezone($timezone)->format('d.m.Y H:i') }}</time>
                        </div>
                        <p>{{ $message->body }}</p>
                        @if($message->attachment_path)
                            <a href="{{ route('account.venue-bookings.conversation.attachment', [$booking, $message]) }}">Скачать: {{ $message->attachment_name }}</a>
                        @endif
                    </article>
                @empty
                    <p class="app-rental-inbox__muted" data-booking-conversation-empty>Сообщений пока нет.</p>
                @endforelse
            </div>
            <form id="booking-conversation-form" class="app-rental-inbox__fields" method="POST"
                action="{{ route('account.venue-bookings.conversation.store', $booking) }}">
                @csrf
                <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label for="booking-message-body">Сообщение
                    <textarea id="booking-message-body" name="body" maxlength="4000" rows="3" required></textarea>
                </label>
                <button class="button primary" type="submit">Отправить сообщение</button>
            </form>
        </section>
    @endif
</section>
@endsection
