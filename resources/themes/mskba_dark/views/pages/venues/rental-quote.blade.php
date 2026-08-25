@php $title = 'Расчёт аренды · '.$venue->name; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(isset($error))<div class="alert alert-danger">{{ $error['message'] }}</div>@endif
        <div class="card mb-4"><div class="card-body">
            <p>Расчёт выполняется по версии условий №{{ $policy->version }}. Он не резервирует площадку.</p>
            <form method="POST" action="{{ route('venues.rental.quote', $venue) }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-5"><label class="form-label">Начало</label><input class="form-control" type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}"></div>
                    <div class="col-md-3"><label class="form-label">Длительность, мин</label><input class="form-control" type="number" name="duration_minutes" required min="{{ $policy->minimum_duration_minutes }}" max="{{ $policy->maximum_duration_minutes }}" step="{{ $policy->time_step_minutes }}" value="{{ old('duration_minutes', $policy->minimum_duration_minutes) }}"></div>
                    <div class="col-md-4"><label class="form-label">Область</label><select class="form-select" name="scope">
                        @if($policy->allows_whole)<option value="whole">Вся площадка</option>@endif
                        @if($policy->allows_halves)<option value="half_a">Половина A</option><option value="half_b">Половина B</option>@endif
                    </select></div>
                </div>
                <button class="btn btn--primary btn--sm mt-3" type="submit">Рассчитать</button>
            </form>
        </div></div>

        @if($quote)
            <div class="card"><div class="card-body">
                <h2 class="h4">Итог предложения</h2>
                <p><strong>{{ number_format($quote->amountMinor / 100, 2, ',', ' ') }} {{ $quote->currency }}</strong></p>
                <p>{{ $quote->startsAt->setTimezone($venue->schedule?->timezone ?? config('app.timezone'))->format('d.m.Y H:i') }}–{{ $quote->endsAt->setTimezone($venue->schedule?->timezone ?? config('app.timezone'))->format('H:i') }}</p>
                <p>Hold: {{ $quote->holdDurationMinutes }} мин. Quote действует до {{ $quote->validUntil->format('d.m.Y H:i') }} UTC.</p>
                <p class="text-muted">Идентификатор: {{ $quote->publicId }}. При отправке заявки сервер повторно проверит этот snapshot.</p>
                @auth
                    <form method="POST" action="{{ route('account.venue-bookings.store') }}">
                        @csrf
                        <input type="hidden" name="quote_id" value="{{ $quote->publicId }}">
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <button class="btn btn--primary btn--sm" type="submit">Отправить заявку</button>
                    </form>
                @else
                    <p><a href="{{ route('login') }}">Войдите</a>, чтобы отправить заявку.</p>
                @endauth
            </div></div>
        @endif
    </div></section>
@endsection
