@php $title = 'Заявка на аренду · '.$booking->venue->name; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        <div class="card mb-4"><div class="card-body">
            <p><strong>Статус:</strong> {{ $booking->status->label() }}</p>
            <p>{{ $booking->starts_at->format('d.m.Y H:i') }}–{{ $booking->ends_at->format('d.m.Y H:i') }}</p>
            @if($booking->hold_expires_at)<p>Удержание до {{ $booking->hold_expires_at->format('d.m.Y H:i') }}</p>@endif
            @if($booking->effective_protection_until)<p>Текущий срок защиты слота: {{ $booking->effective_protection_until->format('d.m.Y H:i') }}</p>@endif
            <p class="text-muted">Серверное время: {{ now()->format('d.m.Y H:i:s') }}</p>
            <p class="text-muted">Версия состояния: {{ $booking->optimistic_version }}</p>

            <div class="venue-management-actions">
                @foreach(['accept' => 'Принять', 'confirm' => 'Подтвердить', 'reject' => 'Отклонить', 'cancel' => 'Отменить'] as $action => $label)
                    @if($actions[$action]['allowed'])
                        <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="btn btn--secondary btn--sm" type="submit">{{ $label }}</button>
                        </form>
                    @endif
                @endforeach
            </div>
        </div></div>

        @if(config('features.venue_rental.attendance_v2'))
            <div class="card mb-4"><div class="card-body">
                <h2 class="h4">Подтверждение явки</h2>
                <p class="text-muted">Ответы участников носят информационный характер и не продлевают удержание слота.</p>
                @if($attendanceRound)
                    <p><a href="{{ route('venue-booking-attendance.show', $attendanceRound) }}">Открыть текущий или последний сбор ответов</a></p>
                @elseif($isRequester && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture() && $attendanceCandidates->isNotEmpty())
                    <form method="POST" action="{{ route('venue-booking-attendance.store', $booking) }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Ответить до</label>
                                <input class="form-control" type="datetime-local" name="deadline_at" required max="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}" value="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Минимум «Пойду»</label>
                                <input class="form-control" type="number" name="minimum_yes_responses" min="1" max="{{ $attendanceCandidates->count() }}" value="{{ min(2, $attendanceCandidates->count()) }}" required>
                            </div>
                        </div>
                        <fieldset class="mt-3"><legend class="h6">Пригласить</legend>
                            @foreach($attendanceCandidates as $candidate)
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="invited_user_ids[]" value="{{ $candidate->user_id }}" checked> {{ $candidate->user->username }}</label>
                            @endforeach
                        </fieldset>
                        <input type="hidden" name="responses_visibility" value="participants">
                        <button class="btn btn--primary btn--sm mt-3" type="submit">Открыть сбор явки</button>
                    </form>
                @else
                    <p>Сбор можно открыть только заявителю во время действующего удержания и при наличии приглашённых участников.</p>
                @endif
            </div></div>
        @endif

        <div class="card mb-4"><div class="card-body">
            <h2 class="h4">Продление удержания</h2>
            @php
                $extensionAllowed = (bool) data_get($booking->quote_snapshot, 'policy.allows_hold_extension', false);
                $extensionLimit = (int) data_get($booking->quote_snapshot, 'policy.maximum_hold_extension_minutes', 0);
                $pendingExtension = $booking->extensionRequests->first(fn($extension) => $extension->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus::PENDING);
            @endphp
            @if($isRequester && $extensionAllowed && !$pendingExtension && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture())
                <p class="text-muted">Можно запросить срок не позднее {{ $booking->hold_expires_at->addMinutes($extensionLimit)->format('d.m.Y H:i') }}.</p>
                <form method="POST" action="{{ route('account.venue-bookings.extensions.store', $booking) }}" class="row g-3 mb-4">
                    @csrf
                    <div class="col-md-4"><label class="form-label">Новый срок</label><input class="form-control" type="datetime-local" name="requested_until" min="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}" max="{{ $booking->hold_expires_at->addMinutes($extensionLimit)->format('Y-m-d\TH:i') }}" required></div>
                    <div class="col-md-6"><label class="form-label">Причина</label><textarea class="form-control" name="reason" maxlength="2000" required></textarea></div>
                    <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Запросить</button></div>
                </form>
            @endif
            @forelse($booking->extensionRequests as $extension)
                <div class="border-top pt-3 mb-3">
                    <p><strong>{{ $extension->status->label() }}</strong>: {{ $extension->previous_deadline_at->format('d.m.Y H:i') }} → {{ $extension->requested_until->format('d.m.Y H:i') }}</p>
                    <p>{{ $extension->reason }}</p>
                    @if($extension->decision_reason)<p class="text-muted">Комментарий: {{ $extension->decision_reason }}</p>@endif
                    @if($extension->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus::PENDING)
                        <div class="venue-management-actions">
                            @if($canDecideExtensions)
                                @foreach(['approve' => 'Одобрить', 'reject' => 'Отклонить'] as $decision => $label)
                                    <form method="POST" action="{{ route('account.venue-bookings.extensions.'.$decision, [$booking, $extension]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">{{ $label }}</button></form>
                                @endforeach
                            @endif
                            @if($isRequester)<form method="POST" action="{{ route('account.venue-bookings.extensions.cancel', [$booking, $extension]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Отменить запрос</button></form>@endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-muted">Запросов на продление пока нет.</p>
            @endforelse
        </div></div>

        @if(config('features.venue_rental.external_payment') && (bool) data_get($booking->quote_snapshot, 'policy.requires_payment', false))
            <div class="card mb-4"><div class="card-body">
                <h2 class="h4">Внешняя оплата</h2>
                <p><strong>Статус:</strong> {{ $booking->payment_state->value }}</p>
                @if(!$booking->paymentAttempt && $canConfirmPayment && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture())
                    <form method="POST" action="{{ route('account.venue-bookings.payment.open', $booking) }}" class="row g-3">
                        @csrf
                        <div class="col-md-3"><label class="form-label">Способ</label><select class="form-control" name="method"><option value="bank_transfer">Банковский перевод</option><option value="cash">Наличные</option><option value="other">Другой</option></select></div>
                        <div class="col-md-7"><label class="form-label">Реквизиты и инструкция</label><textarea class="form-control" name="payment_instructions" maxlength="4000" required></textarea></div>
                        <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Открыть окно</button></div>
                    </form>
                @elseif($booking->paymentAttempt)
                    @php $payment = $booking->paymentAttempt; @endphp
                    <p>{{ number_format($payment->amount_minor / 100, 2, ',', ' ') }} {{ $payment->currency }}, способ: {{ $payment->method }}</p>
                    <p>{{ $payment->payment_instructions }}</p>
                    <p class="text-muted">Оплатить и сообщить до {{ $payment->window_expires_at->format('d.m.Y H:i') }}. Заявление об оплате само по себе не подтверждает бронь.</p>
                    @if($isRequester && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::WINDOW_OPEN && now()->lessThan($payment->window_expires_at))
                        <form method="POST" enctype="multipart/form-data" action="{{ route('account.venue-bookings.payment.claim', [$booking, $payment]) }}" class="row g-3">
                            @csrf
                            <div class="col-md-4"><label class="form-label">Номер/ссылка подтверждения</label><input class="form-control" name="reference" maxlength="255"></div>
                            <div class="col-md-3"><label class="form-label">Комментарий</label><textarea class="form-control" name="note" maxlength="2000"></textarea></div>
                            <div class="col-md-3"><label class="form-label">Чек (JPG, PNG, PDF до 5 МБ)</label><input class="form-control" type="file" name="evidence_file" accept="image/jpeg,image/png,application/pdf"></div>
                            <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Я оплатил</button></div>
                        </form>
                    @endif
                    @if($canConfirmPayment && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::CLAIMED)
                        <div class="venue-management-actions">
                            <form method="POST" action="{{ route('account.venue-bookings.payment.confirm', [$booking, $payment]) }}">@csrf<button class="btn btn--primary btn--sm" type="submit">Подтвердить оплату</button></form>
                            <form method="POST" action="{{ route('account.venue-bookings.payment.reject', [$booking, $payment]) }}">@csrf<input type="hidden" name="reason" value="Доказательство оплаты не принято."><button class="btn btn--secondary btn--sm" type="submit">Отклонить</button></form>
                        </div>
                    @endif
                @endif
            </div></div>
        @endif

        <div class="card"><div class="card-body">
            <h2 class="h4">История</h2>
            <ul>
                @foreach($booking->transitions as $transition)
                    <li>{{ $transition->created_at->format('d.m.Y H:i') }} — {{ $transition->to_status->label() }}@if($transition->reason): {{ $transition->reason }}@endif</li>
                @endforeach
            </ul>
        </div></div>
    </div></section>
@endsection
