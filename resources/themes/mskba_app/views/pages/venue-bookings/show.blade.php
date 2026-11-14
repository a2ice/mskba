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
            <div><dt>Стоимость</dt><dd><strong>{{ $money->format($amountMinor, $currency) }} {{ $money->currencyLabel($currency) }}</strong></dd></div>
            @if($booking->hold_expires_at)
                <div><dt>Удержание до</dt><dd>{{ $booking->hold_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }}</dd></div>
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

    @if(config('features.venue_rental.external_payment') && $canViewPayment && data_get($booking->quote_snapshot, 'policy.requires_payment'))
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

    @if(config('features.venue_rental.conversations') && $conversation)
        <section class="panel app-rental-inbox__summary" aria-labelledby="rental-booking-chat-title">
            <h2 id="rental-booking-chat-title">Переписка по заявке</h2>
            <p class="app-rental-inbox__muted">Сообщения остаются внутри заявки и доступны только её участникам.</p>
            <a class="button secondary" href="{{ route('account.venue-bookings.conversation.index', $booking) }}">Открыть переписку</a>
        </section>
    @endif
</section>
@endsection
