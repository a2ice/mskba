@extends('theme::layouts.app', ['title' => 'Группа интересов: '.$venue->name])

@section('content')
<section class="section app-venue-interest-group" aria-labelledby="venue-interest-group-heading">
    <div class="container">
        <header class="app-venue-interest-group__header">
            <div>
                <p class="eyebrow">Группа интересов площадки</p>
                <h1 id="venue-interest-group-heading">Площадкой интересуются</h1>
                <p class="muted">{{ $venue->name }}</p>
            </div>
            <a class="button secondary" href="{{ route('venues.show', $venue->routeIdentifier()) }}">К площадке</a>
        </header>
        <div class="panel app-venue-interest-group__content">
            <p class="muted">Здесь собираются игроки, которые подписались на эту площадку или подходят по условиям. Подписка ещё не означает подтверждение игры или бронирования.</p>
            <div class="app-venue-interests-dialog__total" aria-label="Всего игроков: {{ $venueAudience['total'] }}">
                <strong class="app-venue-interests-dialog__total-number">{{ $venueAudience['total'] }}</strong>
                <span class="app-venue-interests-dialog__total-word">игроков</span>
            </div>
            <div data-venue-interests-dialog>
                @if($venueAudience['total'] > 0)
                    @include('theme::pages.venues.partials.interest-users-list', ['venueAudience' => $venueAudience])
                @else
                    <p class="muted" role="status">Пока никто не подписался. Стань первым участником группы.</p>
                @endif
            </div>
            <div class="app-venue-interest-group__actions">
                @if($viewerHasVenueInterest)
                    <a class="button primary" href="{{ route('play-intents.mine') }}">Вы подписаны</a>
                @else
                    <a class="button primary" href="{{ route('venues.show', $venue->routeIdentifier()) }}#app-venue-interest">Хочу здесь играть</a>
                @endif
            </div>
        </div>
    </div>
</section>
<dialog class="app-venue-overlay app-venue-player-details-dialog" data-venue-player-details-dialog aria-labelledby="app-venue-player-details-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-player-details-title">Игровая информация</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button>
    </div>
    <div class="app-venue-player-details__body" data-venue-player-details-body></div>
</dialog>
@endsection
