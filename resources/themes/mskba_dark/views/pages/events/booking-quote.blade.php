@php
    $title = 'Расчёт аренды для мероприятия';
    $timezone = $event->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $selectedRental ??= null;
    $amount = $quote ? number_format($quote->amountMinor / 100, 2, ',', ' ') : '0';
@endphp
@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => $event->title,
    'sidebarLabel' => 'Мероприятие',
])
@section('section-sidebar')
    <div class="section-sidebar-block">
        <a class="nav-link" href="{{ route('events.show', $event->routeIdentifier()) }}">Вернуться к мероприятию</a>
    </div>
@endsection
@section('section-content')
    <div class="alert alert-info mb-3">
        Это расчёт, а не бронь. Время может быть занято другим пользователем до отправки заявки.
        После отправки заявка рассматривается по правилам площадки.
    </div>
    <article class="card mb-3"><div class="card-body">
        <h2 class="h5">{{ $event->venue->name }} · {{ $selectedRental['court_name'] ?? $event->court?->name ?? 'Зал' }}</h2>
        @if($selectedRental)
            <p>{{ $selectedRental['date_label'] }} · {{ \App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::from($selectedRental['scope'])->label() }}</p>
            @if($selectedRental['changes_event'])<p>Выбранный интервал аренды отличается от текущих условий мероприятия.</p>@endif
        @else
            <p>{{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }} –
                {{ $event->ends_at->setTimezone($timezone)->format('H:i') }}</p>
        @endif
        <p class="mb-1">Итоговая стоимость аренды с учётом применимых условий и комиссий:</p>
        <strong class="h3">{{ $amount }} {{ $quote === null || $quote->currency === 'RUB' ? '₽' : $quote->currency }}</strong>
        @if($quote)<p class="text-muted mt-2">Расчёт действует до {{ $quote->validUntil->setTimezone($timezone)->format('d.m.Y H:i') }}.</p>@endif
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
            @if($proposalId)
                <p class="alert alert-info mt-3">После отправки заявки предложение представителя площадки будет отмечено как принято. Окончательная цена указана выше и может отличаться от предварительной.</p>
            @endif
            <input type="hidden" name="sync_event_time" value="0">
            <label class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="sync_event_time" value="1" @checked((bool) old('sync_event_time', true))>
                Обновить и подтвердить дату и время мероприятия при подтверждении аренды
            </label>
            <button type="submit" class="btn btn--primary">{{ $quote === null ? 'Подтвердить бесплатную аренду' : ($proposalId ? 'Принять предложение и отправить заявку' : 'Отправить заявку на аренду') }}</button>
            <a href="{{ route('events.edit', $event->routeIdentifier()) }}" class="btn btn--secondary">Изменить место или время</a>
        </form>
    </div></article>
@endsection
