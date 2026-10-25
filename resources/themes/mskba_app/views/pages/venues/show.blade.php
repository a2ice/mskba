@extends('theme::layouts.app', ['breadcrumbs' => [
    ['label' => 'Площадки', 'url' => route('venues.search')],
    ['label' => isset($venue)
        ? (\Illuminate\Support\Str::length($venue->name) > 25
            ? \Illuminate\Support\Str::substr($venue->name, 0, 25).'...'
            : $venue->name)
        : 'Страница', 'url' => null],
]])

@section('content')
@if (!empty($error))
    <section class="container section app-venue-page"><div class="panel app-venue-page__empty">
        <h1>Площадка недоступна</h1>
        <p>{{ $error['message'] ?? 'Не удалось загрузить площадку.' }}</p>
        <a class="button secondary" href="{{ route('venues.search') }}">К каталогу площадок</a>
    </div></section>
@else
@php
    $hero = $venue->featuredMedia[0]['url'] ?? null;
    $venueAddress = $venue->address?->display ?: $venue->rawAddress;
    $isConfirmedVenue = $venue->statusSlug === 'confirmed';
    $canStartInterest = $isConfirmedVenue;
    $activeVenueInterests = \App\Modules\PlayInterest\Domain\Models\PlayIntent::query()
        ->where('venue_id', $venue->id)
        ->where('status', 'active')
        ->where('expires_at', '>', now());
    $viewer = auth()->user()?->canonical();
    $viewerVenueInterest = $viewer
        ? (clone $activeVenueInterests)->where('user_id', $viewer->id)->orderByDesc('id')->first()
        : null;
    $viewerHasVenueInterest = $viewerVenueInterest !== null;
    // New interests explicitly consent to this venue-specific publication.
    // Old interests (default false) remain anonymous until their owners opt in.
    $venueInterestUsers = (clone $activeVenueInterests)
        ->with(['user.profile.activeAvatar', 'user.playerProfile.positions'])
        ->where('show_on_venue', true)
        ->orderByDesc('created_at')->limit(300)->get()
        ->unique('user_id')
        ->filter(fn ($intent) => $intent->user !== null && ! $intent->user->isBlocked())
        ->map(function ($intent): array {
            $user = $intent->user;
            $player = $user->playerProfile;
            $positions = $player?->positions?->map(fn ($position) => $position->position?->label())
                ->filter()->values()->all() ?? [];
            $experience = $player?->experience_started_year
                ? max(0, now()->year - (int) $player->experience_started_year) : null;
            return [
                'name' => $user->username
                    ?: (trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: 'Игрок'),
                'avatar' => $user->profile?->avatarUrl(),
                'positions' => $positions,
                'experience_years' => $experience,
            ];
        })->values();
    // Aggregated counts never identify private users or imply their consent.
    $activeVenueInterestCount = (clone $activeVenueInterests)->distinct()->count('user_id');
    $favoritesCount = class_exists(\App\Modules\Favorites\Domain\Models\Favorite::class)
        ? \App\Modules\Favorites\Domain\Models\Favorite::query()
            ->where('entity_type', 'venue')->where('entity_id', $venue->id)
            ->whereNull('trashed_at')->count()
        : 0;
    $environments = collect($venue->courts)->pluck('environment')->filter()->unique()->values();
    $areaLabel = $environments->contains('indoor') && $environments->contains('outdoor')
        ? 'крытая и уличная площадки'
        : ($environments->contains('indoor') ? 'крытая площадка'
            : ($environments->contains('outdoor') ? 'уличная площадка' : null));
    $hoursByDate = collect($venue->about->scheduleDays)->keyBy('date');
    $shownDays = $venue->isFreeAccess
        ? collect($venue->occupancyDays)->filter(fn (array $day): bool =>
            count($day['slots'] ?? []) > 0
            || count($hoursByDate->get($day['date'])['intervals'] ?? []) > 0)->values()->all()
        : $venue->occupancyDays;
@endphp
<section class="container app-venue-page" aria-labelledby="app-venue-title">
    <div class="app-venue-page__hero-grid">
        <div class="app-venue-gallery panel" data-venue-gallery>
            @if(count($venue->featuredMedia))
                <div class="app-venue-gallery__stage">
                    @foreach($venue->featuredMedia as $photo)
                        <img class="app-venue-gallery__slide{{ $loop->first ? ' is-active' : '' }}"
                            src="{{ $photo['url'] }}" alt="{{ $photo['title'] ?: $venue->name }}"
                            loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                            data-gallery-slide aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                    @endforeach
                    @if(count($venue->featuredMedia) > 1)
                        <div class="app-venue-gallery__thumbs" aria-label="Выбор фотографии">
                            @foreach($venue->featuredMedia as $photo)
                                <button type="button" class="app-venue-gallery__thumb{{ $loop->first ? ' is-active' : '' }}"
                                    data-gallery-thumb="{{ $loop->index }}" aria-label="Показать фото {{ $loop->iteration }}"
                                    aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                                    <img src="{{ $photo['url'] }}" alt="" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <div class="app-venue-gallery__missing"><svg aria-hidden="true"><use href="#ball"/></svg><span>Фотографии площадки пока не загружены</span></div>
            @endif
        </div>
        <div class="app-venue-intro panel">
            <p class="eyebrow accent">{{ $venue->type }}@if($areaLabel): {{ $areaLabel }}@endif</p>
            <h1 id="app-venue-title">{{ $venue->name }}</h1>
            @if($venueAddress)
                <button type="button" class="app-venue-intro__address" data-venue-map-open
                    aria-label="Показать {{ $venueAddress }} на Яндекс Картах">
                    <svg aria-hidden="true"><use href="#pin"/></svg><span>{{ $venueAddress }}</span>
                </button>
            @endif
            @if(count($venue->metroStations))
                <div class="app-venue-intro__metro" aria-label="Ближайшие станции метро">
                    @foreach($venue->metroStations as $station)
                        <span class="app-venue-intro__station">
                            <span class="app-venue-intro__metro-dot"
                                style="--metro-color: {{ ($station->lineColor && preg_match('/^#[0-9a-fA-F]{3,8}$/', $station->lineColor)) ? $station->lineColor : '#758392' }}"
                                aria-hidden="true"></span>{{ $station->name }}
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="app-venue-intro__badges">
                @foreach(array_slice($venue->amenities, 0, 3) as $amenity)<span class="badge">{{ $amenity->name }}</span>@endforeach
            </div>
            @if($venue->shortDescription)<p class="app-venue-intro__copy">{{ $venue->shortDescription }}</p>@endif
            <div class="app-venue-intro__actions">
                <button type="button" class="button primary large" data-play-intent-open data-venue-interest-action
                    data-interest-id="{{ $viewerVenueInterest?->id }}"
                    data-cancel-url="{{ $viewerVenueInterest ? route('play-intents.destroy', $viewerVenueInterest->id) : '' }}"
                    @disabled(!$canStartInterest)>
                    {{ $viewerHasVenueInterest ? 'Не хочу здесь играть' : 'Хочу здесь играть' }}
                </button>
                <a class="button secondary large" href="#app-venue-schedule">Расписание площадки</a>
            </div>
        </div>
    </div>

    <nav class="app-venue-page__anchors" aria-label="Разделы площадки" data-venue-section-nav>
        <a href="#app-venue-activities">Игры</a>
        <a href="#app-venue-interest">Интерес игроков</a>
        <a href="#app-venue-schedule">Расписание</a>
        <a href="#app-venue-about">О площадке</a>
    </nav>

    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            <section class="panel app-venue-block" id="app-venue-activities">
                <header class="app-venue-block__header"><h2>Игры и мероприятия</h2></header>
                <p class="muted">Проверяй расписание игр и присоединяйся к мероприятиям с открытым набором участников.</p>
                <a href="{{ route('events.index') }}" class="button secondary">Смотреть мероприятия <svg aria-hidden="true"><use href="#arrow"/></svg></a>
            </section>
            <section class="panel app-venue-block" id="app-venue-interest">
                <header class="app-venue-block__header"><h2>Площадкой интересуются</h2></header>
                <div class="app-venue-interest-summary" data-play-venue-interest-summary>
                    <div class="app-venue-interest-summary__profiles">
                        <div class="app-venue-interest-summary__avatars" aria-label="Игроки с открытыми профилями">
                            @foreach($venueInterestUsers->take(5) as $player)
                                <span class="app-venue-interest-summary__avatar" title="{{ $player['name'] }}">
                                    @if($player['avatar'])
                                        <img src="{{ $player['avatar'] }}" alt="{{ $player['name'] }}" loading="lazy">
                                    @else
                                        <span aria-hidden="true">{{ mb_strtoupper(mb_substr($player['name'], 0, 1)) }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                        <strong class="app-venue-interest-summary__count">{{ $activeVenueInterestCount }} {{ $activeVenueInterestCount % 100 >= 11 && $activeVenueInterestCount % 100 <= 14 ? 'интересов' : (match ($activeVenueInterestCount % 10) { 1 => 'интерес', 2, 3, 4 => 'интереса', default => 'интересов' }) }}</strong>
                        <button type="button" class="button secondary" data-venue-interests-open>Посмотреть всех</button>
                    </div>
                </div>
            </section>
            <section class="panel app-venue-block" id="app-venue-schedule">
                <header class="app-venue-block__header"><h2>Часы работы</h2></header>
                @if(count($shownDays) > 0)
                    <div class="app-venue-schedule">
                        @foreach($shownDays as $day)
                            @php
                                $publishedHours = $hoursByDate->get($day['date'])['intervals'] ?? [];
                            @endphp
                            <div class="app-venue-schedule__day">
                                <strong>{{ $day['label'] ?? $day['date'] }}</strong>
                                @if(count($publishedHours))
                                    <p class="app-venue-schedule__hours" aria-label="Часы работы">
                                        <svg aria-hidden="true"><use href="#clock"/></svg>
                                        <span>@foreach($publishedHours as $hours){{ $hours['startsAt'] }}–{{ $hours['endsAt'] }}@unless($loop->last), @endunless @endforeach</span>
                                    </p>
                                @endif
                                @if(count($day['slots'] ?? []) > 0)
                                    <div class="app-venue-schedule__slots">
                                        <span class="app-venue-schedule__slots-title">Слоты</span>
                                        <ul class="app-venue-schedule__slot-list">
                                            @foreach($day['slots'] as $slot)
                                                <li>
                                                    <button type="button" class="app-venue-schedule__slot-trigger"
                                                        data-venue-slot-open
                                                        data-slot-day="{{ $day['label'] ?? $day['date'] }}"
                                                        data-slot-time="{{ $slot['timeLabel'] ?? '' }}"
                                                        data-event-type="{{ !empty($slot['eventUrl']) ? ($slot['eventTypeLabel'] ?? 'Мероприятие') : 'Занятое время' }}"
                                                        data-event-title="{{ $slot['eventTitle'] ?? '' }}"
                                                        data-event-url="{{ $slot['eventUrl'] ?? '' }}"
                                                        data-event-join-url="{{ $slot['eventJoinUrl'] ?? '' }}"
                                                        data-can-join="{{ !empty($slot['canJoin']) ? 'true' : 'false' }}"
                                                        aria-label="Подробнее о слоте {{ $day['label'] ?? $day['date'] }}, {{ $slot['timeLabel'] ?? '' }}">
                                                        {{ $slot['timeLabel'] ?? '' }}
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @elseif(! $venue->isFreeAccess)
                                    <p class="muted">Нет опубликованных занятых слотов. Это не подтверждение свободной брони.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif($venue->isFreeAccess)
                    <p class="muted">Опубликованных мероприятий и часов работы пока нет.</p>
                @else
                    <p class="muted">Подтверждённых данных о свободных интервалах пока нет. Уточни доступность перед бронированием.</p>
                @endif
                @unless($venue->isFreeAccess)
                    <p class="caption">Наличие свободного времени и стоимость уточняются при оформлении бронирования.</p>
                @endunless
            </section>
            <section class="panel app-venue-block" id="app-venue-about">
                <header class="app-venue-block__header"><h2>О площадке</h2></header>
                @if($venue->fullDescription)<p class="app-venue-block__description">{{ $venue->fullDescription }}</p>@endif
                @if(count($venue->amenities))
                    <h3>Удобства</h3>
                    <div class="app-venue-block__amenities">
                        @foreach($venue->amenities as $amenity)
                            <span class="badge">{{ $amenity->name }}</span>
                        @endforeach
                    </div>
                @endif
                @if($venue->about->rating !== null)
                    <p>Оценка игроков: {{ $venue->about->rating }} · отзывов: {{ $venue->about->ratingCount ?? 0 }}</p>
                @endif
            </section>
        </div>
        <aside class="app-venue-page__aside panel" aria-label="Быстрые действия">
            <strong>Хочешь поиграть?</strong>
            <p>Укажи время и подходящий формат. MSKBA сохранит твой интерес без обязательств.</p>
            <button type="button" class="button primary full" data-play-intent-open data-venue-interest-action
                data-interest-id="{{ $viewerVenueInterest?->id }}"
                data-cancel-url="{{ $viewerVenueInterest ? route('play-intents.destroy', $viewerVenueInterest->id) : '' }}"
                @disabled(!$canStartInterest)>{{ $viewerHasVenueInterest ? 'Не хочу здесь играть' : 'Хочу здесь играть' }}</button>
            <a class="button secondary full" href="#app-venue-schedule">Посмотреть расписание</a>
        </aside>
    </div>
</section>
@if($venueAddress)
    <dialog class="app-venue-overlay" data-venue-map-dialog aria-label="Положение площадки на карте">
        <div class="app-venue-overlay__header"><h2>{{ $venue->name }} — на карте</h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button></div>
        <div class="app-venue-map-frame" data-venue-map-frame
            data-lat="{{ $venue->address?->latitude }}" data-lon="{{ $venue->address?->longitude }}"
            data-name="{{ $venue->name }}" data-map-api-key="{{ $venue->about->mapApiKey }}"></div>
        <div class="app-venue-map-options">
            <p class="app-venue-map-options__address">{{ $venueAddress }}</p>
            <label class="app-venue-map-options__toggle"><input type="checkbox" data-venue-nearby-toggle> Показать площадки рядом</label>
            <div class="app-venue-map-options__range" data-venue-nearby-options hidden>
                <label for="venue-nearby-radius">Радиус: <output data-venue-nearby-distance>3</output> км</label>
                <input id="venue-nearby-radius" type="range" min="1" max="10" step="1" value="3" data-venue-nearby-radius>
                <button type="button" class="button primary" data-venue-nearby-apply
                    data-nearby-url="{{ route('venues.nearby-map', $venue->routeIdentifier()) }}">Применить</button>
            </div>
            <p class="caption" data-venue-nearby-status role="status" hidden></p>
        </div>
    </dialog>
@endif
<dialog class="app-venue-overlay" data-venue-interests-dialog aria-label="Игроки, заинтересованные в площадке">
    <div class="app-venue-overlay__header"><h2>Площадкой интересуются</h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button></div>
    <div class="app-venue-interests-dialog__list">
        @forelse($venueInterestUsers as $player)
            <div class="app-venue-interests-dialog__player">
                <span class="app-venue-interest-summary__avatar">
                    @if($player['avatar'])
                        <img src="{{ $player['avatar'] }}" alt="" loading="lazy">
                    @else
                        <span aria-hidden="true">{{ mb_strtoupper(mb_substr($player['name'], 0, 1)) }}</span>
                    @endif
                </span>
                <div class="app-venue-interests-dialog__details">
                    <strong>{{ $player['name'] }}</strong>
                    @if(count($player['positions']))<span>{{ implode(' · ', $player['positions']) }}</span>@endif
                    @if($player['experience_years'] !== null)<span>Игровой опыт: {{ $player['experience_years'] }} лет</span>@endif
                </div>
            </div>
        @empty
            <p class="muted">Пока нет игроков, разрешивших показывать свой профиль.</p>
        @endforelse
    </div>
    <button type="button" class="button primary" data-venue-interest-action data-interest-id="{{ $viewerVenueInterest?->id }}"
        data-cancel-url="{{ $viewerVenueInterest ? route('play-intents.destroy', $viewerVenueInterest->id) : '' }}"
        data-play-intent-open>{{ $viewerHasVenueInterest ? 'Не интересно' : 'Тоже хочу здесь играть' }}</button>
</dialog>
<dialog class="app-venue-overlay app-venue-overlay--slot" data-venue-slot-dialog aria-labelledby="app-venue-slot-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-slot-title" data-venue-slot-title>Занятый слот</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button>
    </div>
    <p class="app-venue-slot-dialog__time" data-venue-slot-time></p>
    <p class="muted" data-venue-slot-message></p>
    <div class="app-venue-overlay__actions">
        <a class="button secondary" data-venue-slot-event-link hidden>О мероприятии</a>
        @auth
            <form method="POST" data-venue-slot-join-form hidden>
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="confirmed">
                <button class="button primary" type="submit">Присоединиться</button>
            </form>
        @else
            <a class="button primary" data-venue-slot-guest-link hidden>Присоединиться</a>
        @endauth
    </div>
</dialog>
<dialog class="app-venue-overlay app-venue-overlay--confirm" data-venue-interest-confirm>
    <div class="app-venue-overlay__header"><h2>Отменить интерес?</h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button></div>
    <p>Вы уверены, что больше не хотите следить за этой площадкой?</p>
    <div class="app-venue-overlay__actions">
        <button type="button" class="button secondary" data-venue-overlay-close>Отмена</button>
        <button type="button" class="button primary" data-venue-interest-confirm-submit>Подтвердить</button>
    </div>
    <p class="error" role="alert" data-venue-interest-error hidden></p>
</dialog>
@php
    $playIntentOptions = [
        'venueId' => $venue->id,
        'venueName' => $venue->name,
        'venueUrl' => route('venues.show', $venue->id.'-'.$venue->alias),
        'venueType' => $venue->typeSlug,
        'venueLatitude' => $venue->address?->latitude,
        'venueLongitude' => $venue->address?->longitude,
        'favoriteCount' => $favoritesCount,
        'authenticated' => auth()->check(),
        'endpoints' => [
            'store' => route('play-intents.store'),
            'amenities' => route('play-intents.amenities'),
            'mine' => route('play-intents.mine'),
            'favoriteStatuses' => route('favorites.venue-statuses'),
            'favoriteToggle' => route('favorites.toggle'),
        ],
    ];
@endphp
<div data-mskba-play-intent-root data-options='@json($playIntentOptions)'></div>
@endif
@endsection
