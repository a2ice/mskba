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
