@php
    $title = 'Аренда для мероприятия';
    $timezone = $event->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $currency = $quote->currency === 'RUB' ? '₽' : $quote->currency;
    $amount = number_format($quote->amountMinor / 100, 2, ',', ' ');
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner">
        <header class="app-account-section__heading">
            <p><a href="{{ route('events.show', $event->routeIdentifier()) }}">← К мероприятию</a></p>
            <h1>{{ $title }}</h1>
            <p>{{ $event->title }}</p>
        </header>
        <section class="panel" aria-labelledby="booking-quote-heading">
            <h2 id="booking-quote-heading">{{ $event->venue->name }}</h2>
            <p>{{ $event->court?->name ?? 'Зал' }} ·
                {{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $event->ends_at->setTimezone($timezone)->format('H:i') }}</p>
            <p>Итоговая стоимость аренды с тарифами и комиссиями:</p>
            <h3>{{ $amount }} {{ $currency }}</h3>
            <p>Расчёт действителен до {{ $quote->validUntil->setTimezone($timezone)->format('d.m.Y H:i') }}.
                Это ещё не бронь; время может быть занято до подачи заявки.</p>
            @if($proposalId)
                <p>Вы принимаете предложение представителя площадки. После отправки действует стандартный порядок бронирования и отмены.</p>
            @endif
            <form method="POST" action="{{ route('events.booking.store', $event->routeIdentifier()) }}">
                @csrf
                <input type="hidden" name="expected_version" value="{{ $expectedVersion }}">
                <input type="hidden" name="quote_id" value="{{ $quote->publicId }}">
                @if($interestId)<input type="hidden" name="interest_id" value="{{ $interestId }}">@endif
                @if($proposalId)<input type="hidden" name="proposal_id" value="{{ $proposalId }}">@endif
                <div class="app-rental-pricing__actions">
                    <button class="button primary" type="submit">{{ $proposalId ? 'Принять предложение и отправить заявку' : 'Отправить заявку на аренду' }}</button>
                    <a class="button secondary" href="{{ route('events.edit', $event->routeIdentifier()) }}">Изменить время или площадку</a>
                </div>
            </form>
        </section>
    </div>
</section>
@endsection
