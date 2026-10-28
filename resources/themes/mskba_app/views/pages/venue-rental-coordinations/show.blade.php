@extends('theme::layouts.app', ['title' => $coordination->title])

@section('content')
@php($venueTimezone = $coordination->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'))
<section class="section app-venue-interest-group" aria-labelledby="rental-gathering-heading">
    <div class="container">
        <header class="app-venue-interest-group__header">
            <div>
                <p class="eyebrow">Совместная аренда</p>
                <h1 id="rental-gathering-heading">{{ $coordination->title }}</h1>
                <p class="muted"><a href="{{ route('venues.interest-group', ['venue' => $coordination->venue_id]) }}">{{ $coordination->venue->name }} · Группа интересов</a></p>
            </div>
            <a class="button secondary" href="{{ route('venues.interest-group', ['venue' => $coordination->venue_id]) }}">К группе</a>
        </header>
        <div class="panel app-venue-interest-group__content">
            @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
            @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
            @if($coordination->booking?->status->occupiesVenue())
                <p role="status"><strong>{{ $coordination->booking->status->label() }}.</strong> Слот удерживается или подтверждён.</p>
            @else
                <p role="status" class="muted"><strong>Слот не забронирован.</strong> Сбор показывает интерес игроков, но не гарантирует наличие площадки и не является оплатой.</p>
            @endif
            <dl class="app-group-rentals__details">
                <div><dt>Площадка</dt><dd><a href="{{ route('venues.show', $coordination->venue->routeIdentifier()) }}">{{ $coordination->venue->name }}</a></dd></div>
                <div><dt>Начало</dt><dd>{{ $coordination->starts_at->timezone($venueTimezone)->format('d.m.Y H:i') }}</dd></div>
                <div><dt>Окончание</dt><dd>{{ $coordination->ends_at->timezone($venueTimezone)->format('d.m.Y H:i') }}</dd></div>
                <div><dt>Зона</dt><dd>{{ $coordination->scope->label() }}</dd></div>
                <div><dt>Статус</dt><dd>{{ $coordination->status->label() }}</dd></div>
            </dl>
            @if($coordination->description)<p>{{ $coordination->description }}</p>@endif
            @auth
                <div class="app-group-rentals__actions">
                    @if($coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN && !$isParticipant)
                        <form method="POST" action="{{ route('venue-rental-coordinations.join', $coordination) }}">
                            @csrf<button class="button primary" type="submit">Присоединиться к сбору</button>
                        </form>
                    @elseif($coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN && $isParticipant && !$isOrganizer)
                        <form method="POST" action="{{ route('venue-rental-coordinations.leave', $coordination) }}">
                            @csrf<button class="button secondary" type="submit">Покинуть сбор</button>
                        </form>
                    @endif
                    @if($isOrganizer && $coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN)
                        <form method="POST" action="{{ route('venue-rental-coordinations.close', $coordination) }}">
                            @csrf<button class="button secondary" type="submit">Закрыть сбор</button>
                        </form>
                    @endif
                    @if($isOrganizer && in_array($coordination->status, [
                        \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN,
                        \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::CLOSED,
                    ], true))
                        <form method="POST" action="{{ route('venue-rental-coordinations.convert', $coordination) }}">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="button primary" type="submit">Подать заявку на аренду</button>
                        </form>
                    @endif
                    @if($isOrganizer && $coordination->booking)
                        <a class="button secondary" href="{{ route('account.venue-bookings.show', $coordination->booking) }}">Открыть заявку на аренду</a>
                    @endif
                </div>
            @endauth
        </div>
        <div class="panel app-venue-interest-group__content app-group-rentals__participants">
            <h2>Заинтересованных: {{ $activeParticipants->count() }}</h2>
            @if($canSeeParticipants)
                <ul>@foreach($activeParticipants as $participant)
                    <li>{{ $participant->user->username }}</li>
                @endforeach</ul>
            @else
                <p class="muted">Список виден только участникам сбора.</p>
            @endif
        </div>
    </div>
</section>
@endsection
