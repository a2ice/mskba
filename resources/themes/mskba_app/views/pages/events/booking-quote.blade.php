@php
    $title = 'Аренда для мероприятия';
    $timezone = $event->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $selectedRental ??= null;
    $currency = $quote === null || $quote->currency === 'RUB' ? '₽' : $quote->currency;
    $amount = $quote ? number_format($quote->amountMinor / 100, 2, ',', ' ') : '0';
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
            @if($selectedRental)
                <p>{{ $selectedRental['court_name'] }} · {{ $selectedRental['date_label'] }} · {{ \App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::from($selectedRental['scope'])->label() }}</p>
                @if($selectedRental['changes_event'])<p>Выбранный интервал аренды отличается от текущих условий мероприятия.</p>@endif
            @else
                <p>{{ $event->court?->name ?? 'Зал' }} · {{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $event->ends_at->setTimezone($timezone)->format('H:i') }}</p>
            @endif
            <p>Итоговая стоимость аренды с тарифами и комиссиями:</p>
            <h3>{{ $amount }} {{ $currency }}</h3>
            @if($quote)
                <p>Расчёт действителен до {{ $quote->validUntil->setTimezone($timezone)->format('d.m.Y H:i') }}. Это ещё не бронь; время может быть занято до подачи заявки.</p>
            @else
                <p>Бесплатная аренда. Доступность времени будет повторно проверена при подтверждении.</p>
            @endif
            @if($proposalId)
                <p>Вы принимаете предложение представителя площадки. После отправки действует стандартный порядок бронирования и отмены.</p>
            @endif
            <form method="POST" action="{{ route('events.booking.store', $event->routeIdentifier()) }}">
                @csrf
                <input type="hidden" name="expected_version" value="{{ $expectedVersion }}">
                @if($quote)<input type="hidden" name="quote_id" value="{{ $quote->publicId }}">@endif
                @if($selectedRental)
                    <input type="hidden" name="rental_action" value="select">
                    @foreach(['venue_court_id','starts_at','duration_minutes','scope'] as $field)
                        <input type="hidden" name="{{ $field }}" value="{{ $selectedRental[$field] }}">
                    @endforeach
                @endif
                @if($interestId)<input type="hidden" name="interest_id" value="{{ $interestId }}">@endif
                @if($proposalId)<input type="hidden" name="proposal_id" value="{{ $proposalId }}">@endif
                <input type="hidden" name="sync_event_time" value="0">
                <label style="display:flex;align-items:flex-start;gap:8px;margin:12px 0">
                    <input type="checkbox" name="sync_event_time" value="1" @checked((bool) old('sync_event_time', true))>
                    Обновить и подтвердить дату и время мероприятия при подтверждении аренды
                </label>
                <div class="app-rental-pricing__actions">
                    <button class="button primary" type="submit">{{ $quote === null ? 'Подтвердить бесплатную аренду' : ($proposalId ? 'Принять предложение и отправить заявку' : 'Отправить заявку на аренду') }}</button>
                    @if($selectedRental)
                        <a class="button secondary" href="{{ route('events.show', $event->routeIdentifier()) }}?rental=1">Выбрать другое время</a>
                    @else
                        <a class="button secondary" href="{{ route('events.edit', $event->routeIdentifier()) }}">Изменить время или площадку</a>
                    @endif
                </div>
            </form>
        </section>
    </div>
</section>
@endsection
