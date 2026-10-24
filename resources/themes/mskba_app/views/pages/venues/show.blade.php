@extends('theme::layouts.app', ['breadcrumbs' => [
    ['label' => 'Площадки', 'url' => route('venues.index')],
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
    $activeVenueInterestCount = (clone $activeVenueInterests)->count();
    $viewerHasVenueInterest = auth()->check()
        && (clone $activeVenueInterests)
            ->where('user_id', auth()->user()->canonical()->id)
            ->exists();
@endphp
<section class="container app-venue-page" aria-labelledby="app-venue-title">
    <div class="app-venue-page__hero-grid">
        <div class="app-venue-gallery panel">
            @if($hero)
                <img class="app-venue-gallery__main" src="{{ $hero }}" alt="Площадка {{ $venue->name }}" loading="eager">
            @else
                <div class="app-venue-gallery__missing"><svg aria-hidden="true"><use href="#ball"/></svg><span>Фотографии площадки пока не загружены</span></div>
            @endif
            @if(count($venue->featuredMedia) > 1)
                <div class="app-venue-gallery__thumbs">
                @foreach(array_slice($venue->featuredMedia, 1, 4) as $photo)
                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Открыть фото {{ $loop->iteration + 1 }}">
                        <img src="{{ $photo['url'] }}" alt="{{ $photo['title'] ?: 'Фото площадки' }}" loading="lazy">
                    </a>
                @endforeach
                </div>
            @endif
        </div>
        <div class="app-venue-intro panel">
            <p class="eyebrow accent">MSKBA / ПЛОЩАДКА</p>
            <h1 id="app-venue-title">{{ $venue->name }}</h1>
            @if($venueAddress)<p class="app-venue-intro__address"><svg aria-hidden="true"><use href="#pin"/></svg>{{ $venueAddress }}</p>@endif
            <div class="app-venue-intro__badges">
                <span class="badge">{{ $venue->type }}</span>
                @foreach(array_slice($venue->amenities, 0, 3) as $amenity)<span class="badge">{{ $amenity->name }}</span>@endforeach
            </div>
            @if($venue->shortDescription)<p class="app-venue-intro__copy">{{ $venue->shortDescription }}</p>@endif
            <div class="app-venue-intro__actions">
                <button type="button" class="button primary large" data-play-intent-open @disabled(!$canStartInterest)>
                    <svg aria-hidden="true"><use href="#ball"/></svg> Хочу здесь играть
                </button>
                <a class="button secondary large" href="#app-venue-schedule">Расписание и бронь</a>
            </div>
            <p class="app-venue-intro__hint">Можно просто оставить интерес — без брони и обязательств.</p>
        </div>
    </div>

    <nav class="app-venue-page__anchors" aria-label="Разделы площадки">
        <a href="#app-venue-activities">Игры</a>
        <a href="#app-venue-interest">Интерес игроков</a>
        <a href="#app-venue-schedule">Расписание</a>
        <a href="#app-venue-about">О площадке</a>
    </nav>

    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            <section class="panel app-venue-block" id="app-venue-activities">
                <header class="app-venue-block__header"><h2>Игры и мероприятия</h2><span class="badge">Существующие активности</span></header>
                <p class="muted">Проверяй расписание игр и присоединяйся к мероприятиям с открытым набором участников.</p>
                <a href="{{ route('events.index') }}" class="button secondary">Смотреть мероприятия <svg aria-hidden="true"><use href="#arrow"/></svg></a>
            </section>
            <section class="panel app-venue-block" id="app-venue-interest">
                <header class="app-venue-block__header"><h2>Интерес игроков</h2></header>
                <p class="muted">Отметь, когда тебе удобно играть здесь. Совпадения по времени и формату появятся в разделе «Мои интересы» по мере подключения подбора игроков.</p>
                <div class="app-venue-interest-summary" data-play-venue-interest-summary>
                    <p class="muted">Активных интересов по этой площадке: <strong>{{ $activeVenueInterestCount }}</strong></p>
                    @if ($viewerHasVenueInterest)
                        <p class="app-venue-interest-summary__own">Ты уже отметил интерес к игре здесь. <a href="{{ route('play-intents.mine') }}">Мои интересы</a></p>
                    @endif
                    <p class="caption">Отображается только число интересов — не список пользователей. Это не запись на игру.</p>
                </div>
                <button type="button" class="button primary" data-play-intent-open @disabled(!$canStartInterest)>Хочу здесь играть</button>
            </section>
            <section class="panel app-venue-block" id="app-venue-schedule">
                <header class="app-venue-block__header"><h2>Расписание площадки</h2></header>
                @if(count($venue->courts) > 0)
                    <div class="app-venue-block__court-list">
                        @foreach($venue->courts as $court)
                            <span class="badge">{{ $court['name'] }}</span>
                        @endforeach
                    </div>
                @endif
                @if(count($venue->occupancyDays) > 0)
                    <div class="app-venue-schedule">
                        @foreach($venue->occupancyDays as $day)
                            <div class="app-venue-schedule__day">
                                <strong>{{ $day['label'] ?? $day['date'] }}</strong>
                                @forelse($day['slots'] ?? [] as $slot)
                                    <p>{{ $slot['timeLabel'] ?? '' }} · {{ $slot['eventTypeLabel'] ?? 'Занято' }} @if(!empty($slot['eventUrl']))<a href="{{ $slot['eventUrl'] }}">Открыть</a>@endif</p>
                                @empty
                                    <p class="muted">Нет опубликованных занятых слотов. Это не подтверждение свободной брони.</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="muted">Подтверждённых данных о свободных интервалах пока нет. Уточни доступность перед бронированием.</p>
                @endif
                <p class="caption">Наличие свободного времени и стоимость уточняются при оформлении бронирования.</p>
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
            <button type="button" class="button primary full" data-play-intent-open @disabled(!$canStartInterest)>Хочу здесь играть</button>
            <a class="button secondary full" href="#app-venue-schedule">Посмотреть расписание</a>
        </aside>
    </div>
</section>
@php
    $playIntentOptions = [
        'venueId' => $venue->id,
        'venueName' => $venue->name,
        'venueUrl' => route('venues.show', $venue->id.'-'.$venue->alias),
        'venueType' => $venue->typeSlug,
        'venueLatitude' => $venue->address?->latitude,
        'venueLongitude' => $venue->address?->longitude,
        'authenticated' => auth()->check(),
        'endpoints' => [
            'store' => route('play-intents.store'),
            'amenities' => route('play-intents.amenities'),
            'mine' => route('play-intents.mine'),
        ],
    ];
@endphp
<div data-mskba-play-intent-root data-options='@json($playIntentOptions)'></div>
@endif
@endsection
