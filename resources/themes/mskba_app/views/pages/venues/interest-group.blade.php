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
            <div data-venue-interest-group-users>
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
    <section class="panel app-venue-interest-group__content app-group-rentals" aria-labelledby="group-rentals-heading">
        <div class="app-group-rentals__heading">
            <div>
                <h2 id="group-rentals-heading">Совместная аренда и сборы</h2>
                <p class="muted">Здесь отображаются сборы для совместной аренды площадки. Сбор не является игрой, подтверждением участия, бронью или оплатой.</p>
            </div>
        </div>
        @if(! $rentalEnabled)
            <p class="muted" role="status">Сервис совместной аренды для этой группы пока недоступен.</p>
        @else
            @if($gatherings->isEmpty())
                <p class="muted" role="status">Пока нет доступных сборов на этой площадке.</p>
            @else
                <div class="app-group-rentals__list">
                    @foreach($gatherings as $gathering)
                        @php($myGathering = in_array((int)$gathering->organizer_user_id, $viewerIdentityIds, true))
                        <article class="app-group-rentals__card">
                            <div class="app-group-rentals__card-top">
                                <h3><a href="{{ route('venue-rental-coordinations.show', $gathering) }}">{{ $gathering->title }}</a></h3>
                                <span class="badge">{{ $gathering->status->label() }}</span>
                            </div>
                            <p class="muted">{{ $gathering->starts_at->timezone($venueTimezone)->format('d.m.Y H:i') }} — {{ $gathering->ends_at->timezone($venueTimezone)->format('d.m.Y H:i') }}</p>
                            <div class="app-group-rentals__facts">
                                <span>{{ $gathering->scope->label() }}</span>
                                <span>Заинтересованных: {{ $gathering->active_participants_count }}</span>
                                @if($myGathering)<span>Вы организатор</span>@endif
                                @if($gathering->booking)
                                    <span>{{ $gathering->booking->status->occupiesVenue() ? 'Слот удерживается или подтверждён' : 'Заявка на аренду: '.$gathering->booking->status->label() }}</span>
                                @else
                                    <span>Слот не забронирован</span>
                                @endif
                            </div>
                            <a class="button secondary" href="{{ route('venue-rental-coordinations.show', $gathering) }}">Перейти к сбору</a>
                        </article>
                    @endforeach
                </div>
                {{ $gatherings->links() }}
            @endif
            @if(session('error'))
                <p role="alert">{{ session('error') }}</p>
            @endif
            @if($errors->any())
                <p role="alert">{{ $errors->first() }}</p>
            @endif
            @if($canStartRentalGathering)
                <details class="app-group-rentals__create">
                    <summary class="button secondary">Начать сбор на совместную аренду</summary>
                    <p class="muted">Укажите конкретный интервал. Доступность и стоимость проверяются сервером. Создание сбора не удерживает время площадки.</p>
                    <form method="POST" action="{{ route('venue-rental-coordinations.store') }}" class="app-group-rentals__form">
                        @csrf
                        <input type="hidden" name="venue_id" value="{{ $venue->id }}">
                        <input type="hidden" name="visibility" value="public">
                        <input type="hidden" name="participants_visibility" value="participants">
                        <label>Название сбора
                            <input type="text" name="title" maxlength="150" required value="{{ old('title') }}" placeholder="Например, аренда зала для игры">
                        </label>
                        <label>Начало (местное время площадки)
                            <input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}">
                        </label>
                        <label>Длительность, минут
                            <input type="number" name="duration_minutes" min="30" max="480" required value="{{ old('duration_minutes', 90) }}">
                        </label>
                        <label>Зона аренды
                            <select name="scope">
                                @foreach(\App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::cases() as $option)
                                    <option value="{{ $option->value }}" @selected(old('scope', 'whole') === $option->value)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="button primary" type="submit">Проверить условия и создать сбор</button>
                    </form>
                </details>
            @elseif(!$isAuthenticated)
                <p class="muted">Для запуска сбора необходимо войти в аккаунт и подписаться на площадку.</p>
            @endif
        @endif
    </section>
    @if($visibleOffers !== null && $visibleOffers->isNotEmpty())
        @include('theme::pages.venues.partials.group-offers')
    @endif
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
