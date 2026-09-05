@extends('theme::layouts.app', ['title' => 'MSKBA — баскетбол в Москве'])

@section('content')
@php
    $timezone = (string) config('app.timezone', 'Europe/Moscow');
    $nowLocal = now($timezone);
    $today = $nowLocal->toDateString();
    $gamesUrl = route('events.index', ['type' => 'games']);
    $createGameUrl = route('events.create', ['type' => 'game']);

    $homeEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('ends_at', '>', now())
        ->with(['venue.location.address', 'venue.media'])
        ->withCount(['participants as participants_count' => fn ($query) => $query->where('status', 'confirmed')])
        ->orderBy('starts_at')->limit(3)->get();

    $upcomingGamesCount = \App\Modules\Event\Domain\Models\Event::query()
        ->whereIn('type', [
            \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME->value,
            \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME_TRAINING->value,
        ])
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('starts_at', '>', now())
        ->count();

    $upcomingGamesModulo100 = $upcomingGamesCount % 100;
    $upcomingGamesModulo10 = $upcomingGamesCount % 10;
    $upcomingGamesText = match (true) {
        $upcomingGamesModulo100 >= 11 && $upcomingGamesModulo100 <= 14 => "{$upcomingGamesCount} предстоящих игр",
        $upcomingGamesModulo10 === 1 => "{$upcomingGamesCount} предстоящая игра",
        $upcomingGamesModulo10 >= 2 && $upcomingGamesModulo10 <= 4 => "{$upcomingGamesCount} предстоящие игры",
        default => "{$upcomingGamesCount} предстоящих игр",
    };

    $homeVenues = \App\Modules\Venue\Domain\Models\Venue::query()
        ->where('status', \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED->value)
        ->where('operational_status', \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value)
        ->with(['location.address', 'media'])
        ->latest('updated_at')->limit(3)->get();

    $hiringTeams = \App\Modules\Team\Domain\Models\Team::query()
        ->competitionEligible()
        ->whereHas('hiringPositions', fn ($query) => $query->available())
        ->with(['logo', 'hiringPositions' => fn ($query) => $query->available()])
        ->latest('updated_at')->limit(3)->get();

    $homeTeams = \App\Modules\Team\Domain\Models\Team::query()
        ->competitionEligible()
        ->when($hiringTeams->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $hiringTeams->pluck('id')))
        ->with('logo')->latest('updated_at')->limit(3)->get();

    $currentTournament = \App\Modules\Tournament\Domain\Models\Tournament::query()
        ->where('status', \App\Modules\Tournament\Domain\Enums\TournamentStatusEnum::CONFIRMED->value)
        ->whereNull('tournament_closed_at')
        ->whereDate('starts_on', '<=', $today)
        ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
        ->with(['cover', 'defaultVenue'])->withCount(['entries', 'matches'])
        ->latest('starts_on')->first();

    $featuredTournament = $currentTournament ?: \App\Modules\Tournament\Domain\Models\Tournament::query()
        ->where('status', \App\Modules\Tournament\Domain\Enums\TournamentStatusEnum::CONFIRMED->value)
        ->whereNull('tournament_closed_at')->whereDate('starts_on', '>', $today)
        ->with(['cover', 'defaultVenue'])->withCount(['entries', 'matches'])
        ->orderBy('starts_on')->first();

    $latestNews = \App\Modules\Content\Domain\Models\ContentItem::query()
        ->publishedInFeed()->with('cover')->latest('feed_published_at')->limit(3)->get();

    $highlightItems = [
        ['anchor' => '#play', 'icon' => 'ti ti-ball-basketball', 'title' => 'Игры и тренировки', 'text' => 'Находи игру рядом или собирай свою.'],
        ['anchor' => '#venues', 'icon' => 'ti ti-map-2', 'title' => 'Площадки и аренда', 'text' => 'Корты, залы, расписание и бронирование.'],
        ['anchor' => '#teams', 'icon' => 'ti ti-users-group', 'title' => 'Команды', 'text' => 'Создавай состав или присоединяйся к другим.'],
        ['anchor' => '#tournaments', 'icon' => 'ti ti-trophy', 'title' => 'Турниры', 'text' => 'Участвуй, следи за матчами или организуй свой.'],
    ];
@endphp

<section class="home-welcome">
    <div class="home-welcome__image"><img src="{{ asset('images/bg-home-welcome-screen.png') }}" alt=""></div>
    <div class="home-welcome__overlay"></div>
    <div class="home-welcome__content inner">
        <div class="home-welcome__main">
            <div class="home-welcome__copy">
                <div class="home-welcome__badges">
                    <div class="home-welcome__eyebrow">
                        <span class="home-welcome__eyebrow-dot"></span>
                        @if($upcomingGamesCount > 0)
                            <a href="{{ $gamesUrl }}">{{ $upcomingGamesText }}</a>
                        @else
                            <button type="button" class="home-status-action js-handler" data-handler="modal" data-modal-action="open" data-modal-target="home-event-flow" data-modal-section="create"><i class="ti ti-plus"></i> Новая игра</button>
                        @endif
                    </div>
                    <p class="home-welcome__eyebrow home-welcome__eyebrow--online" data-online-summary @if($siteSummary->onlineUsers === 0) hidden @endif>
                        <span class="home-welcome__eyebrow-dot home-welcome__eyebrow-dot--online"></span>
                        <span><span data-online-users-count>{{ $siteSummary->onlineUsers }}</span> онлайн</span>
                    </p>
                </div>

                <p class="home-welcome__kicker">Московская баскетбольная ассоциация</p>
                <h1 class="home-welcome__title">Играй в баскетбол<br><span>где и когда удобно</span></h1>
                <p class="home-welcome__subtitle">MSKBA объединяет игроков, команды, площадки и организаторов Москвы и области. Найди игру рядом, собери свою, забронируй площадку или участвуй в турнире.</p>

                <div class="home-welcome__actions">
                    <button class="btn btn--primary btn--lg home-cta js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-event-flow" data-modal-section="search"><i class="ti ti-ball-basketball"></i><span>Игры и тренировки</span><i class="ti ti-arrow-right"></i></button>
                    <button class="btn btn--secondary btn--lg home-cta js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-venue-flow" data-modal-section="search"><i class="ti ti-map-pin"></i><span>Площадки</span></button>
                </div>

                <a class="home-welcome__how" href="#highlights">О проекте <i class="ti ti-arrow-down"></i></a>
            </div>
        </div>
    </div>
</section>

<section class="home-highlights-section inner" id="highlights">
    <header class="home-section-head home-section-head--compact">
        <p class="home-section-head__eyebrow">Всё для баскетбола в одном месте</p>
        <h2>Выбирай, что нужно тебе сегодня</h2>
    </header>
    <div class="home-highlights">
        @foreach($highlightItems as $item)
            <a class="home-highlight" href="{{ $item['anchor'] }}">
                <span class="home-highlight__number">0{{ $loop->iteration }}</span>
                <span class="home-highlight__icon"><i class="{{ $item['icon'] }}"></i></span>
                <span class="home-highlight__content"><strong>{{ $item['title'] }}</strong><small>{{ $item['text'] }}</small><em>Подробнее <i class="ti ti-arrow-down"></i></em></span>
            </a>
        @endforeach
    </div>
</section>

<section class="home-product-section" id="play">
    <div class="inner">
        <header class="home-section-head">
            <div><p class="home-section-head__eyebrow"><i class="ti ti-ball-basketball"></i> Игры и тренировки</p><h2>Найди игру на сегодня.<br>Или собери свою.</h2></div>
            <p class="home-section-head__lead">Открытые игры и тренировки с понятным временем, площадкой и участниками. Не нужно собирать всё по чатам.</p>
        </header>
        <div class="home-card-grid">
            @forelse($homeEvents as $event)
                @php
                    $start = $event->starts_at->setTimezone($timezone);
                    $eventImage = $event->venue?->media?->sortByDesc('is_featured')->first()?->publicUrl() ?? asset('images/home-court.png');
                @endphp
                <a class="home-event-card" href="{{ route('events.show', $event->routeIdentifier()) }}">
                    <div class="home-event-card__media"><img src="{{ $eventImage }}" alt=""><span>{{ $event->type->label() }}</span><b>{{ $start->format('d.m') }}</b></div>
                    <div class="home-event-card__body"><small><i class="ti ti-clock"></i>{{ $start->format('H:i') }}–{{ $event->ends_at->setTimezone($timezone)->format('H:i') }}</small><h3>{{ $event->title }}</h3><p><i class="ti ti-map-pin"></i>{{ $event->venue?->name ?: 'Площадка уточняется' }}</p><footer><span><i class="ti ti-users"></i>{{ $event->participants_count }}@if($event->max_participants) / {{ $event->max_participants }}@endif</span><i class="ti ti-arrow-up-right"></i></footer></div>
                </a>
            @empty
                <div class="home-empty-card"><i class="ti ti-ball-basketball"></i><strong>Пока нет ближайших публичных игр</strong><p>Создай первую — остальные смогут присоединиться.</p></div>
            @endforelse
        </div>
        <div class="home-section-actions"><a class="btn btn--primary" href="{{ route('events.index') }}">Все мероприятия <i class="ti ti-arrow-right"></i></a><button class="home-text-link js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-event-flow" data-modal-section="create">Создать мероприятие <i class="ti ti-plus"></i></button></div>
    </div>
</section>

<section class="home-product-section home-product-section--alt" id="venues">
    <div class="inner home-split home-split--venues">
        <div class="home-split__copy">
            <p class="home-section-head__eyebrow"><i class="ti ti-map-2"></i> Площадки и аренда</p>
            <h2>Найди место,<br>где играют.</h2>
            <p>Уличные площадки и спортивные залы. Фото, характеристики, расписание и онлайн-бронирование там, где оно доступно.</p>
            <div class="home-venue-search-shell">
                <label class="home-search-field"><i class="ti ti-search"></i><input type="search" placeholder="Название, адрес, район или метро" aria-label="Поиск площадки"><span>Предикативный поиск</span></label>
                <div class="home-filter-row"><button type="button"><i class="ti ti-train"></i> Метро</button><button type="button"><i class="ti ti-map"></i> На карте</button><button type="button"><i class="ti ti-adjustments-horizontal"></i> Фильтры</button></div>
            </div>
            <div class="home-section-actions"><button class="btn btn--primary js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-venue-flow" data-modal-section="search">Найти площадку <i class="ti ti-arrow-right"></i></button><a class="home-text-link" href="{{ route('venues.create') }}">Добавить площадку <i class="ti ti-plus"></i></a></div>
        </div>
        <div class="home-venue-discovery">
            <div class="home-venue-map-preview" aria-label="Карта площадок — заготовка">
                <div class="home-venue-map-preview__grid"></div>
                @foreach($homeVenues as $venue)
                    <a class="home-venue-map-pin home-venue-map-pin--{{ $loop->iteration }}" href="{{ route('venues.show', $venue->routeIdentifier()) }}" title="{{ $venue->name }}"><span>{{ $loop->iteration }}</span></a>
                @endforeach
                <div class="home-venue-map-preview__caption"><i class="ti ti-map-2"></i><strong>Карта площадок</strong><small>Подключим фильтрацию и реальные точки следующим шагом</small></div>
            </div>
            <p class="home-venue-popular-label">Популярные</p>
            <div class="home-venue-stack">
                @forelse($homeVenues as $venue)
                    @php
                        $venueAddress = $venue->location?->address?->full_address ?: $venue->raw_address;
                    @endphp
                    <a class="home-venue-card" href="{{ route('venues.show', $venue->routeIdentifier()) }}"><img src="{{ $venue->media->sortByDesc('is_featured')->first()?->publicUrl() ?? asset('images/venue-placeholder.png') }}" alt=""><span class="home-venue-card__shade"></span><span class="home-venue-card__body"><small>{{ $venue->type->label() }} · {{ $venue->requires_payment === true ? 'аренда' : ($venue->requires_payment === false ? 'бесплатно' : 'условия уточняются') }}</small><strong>{{ $venue->name }}</strong>@if($venueAddress)<em><i class="ti ti-map-pin"></i>{{ \Illuminate\Support\Str::limit($venueAddress, 64) }}</em>@endif</span><i class="ti ti-arrow-up-right home-card-arrow"></i></a>
                @empty
                    <div class="home-empty-card"><i class="ti ti-map-pin"></i><strong>Каталог площадок растёт</strong><p>Добавляй знакомые залы и корты.</p></div>
                @endforelse
            </div>
        </div>
    </div>
</section>

<section class="home-product-section" id="teams">
    <div class="inner">
        <header class="home-section-head">
            <div><p class="home-section-head__eyebrow"><i class="ti ti-users-group"></i> Команды</p><h2>Играй не один.</h2></div>
            <p class="home-section-head__lead">Создай команду, собери состав или присоединись к существующей. Команда живёт на портале между играми и турнирами.</p>
        </header>

        @if($hiringTeams->isNotEmpty())
            <section class="home-team-hiring" aria-labelledby="home-team-hiring-title">
                <div class="home-team-hiring__head"><p class="home-section-head__eyebrow"><i class="ti ti-user-search"></i> Команды ищут игроков</p><h3 id="home-team-hiring-title">Открыт набор</h3></div>
                <div class="home-team-grid">
                    @foreach($hiringTeams as $team)
                        @php
                            $spots = $team->hiringPositions->sum(fn ($position) => $position->remainingSpots());
                        @endphp
                        <a class="home-team-card home-team-card--hiring" href="{{ route('teams.show', $team->routeIdentifier()) }}"><img src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt=""><div><small>ВАКАНСИИ · {{ $spots }} мест</small><h3>{{ $team->name }}</h3><p>{{ \Illuminate\Support\Str::limit($team->description ?: 'Команда открыла набор игроков.', 105) }}</p><strong>Посмотреть набор <i class="ti ti-arrow-right"></i></strong></div></a>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="home-team-grid home-team-grid--regular">
            @forelse($homeTeams as $team)
                <a class="home-team-card" href="{{ route('teams.show', $team->routeIdentifier()) }}"><img src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt=""><div><small>КОМАНДА @if($team->accepts_join_requests)<b>· ПРИНИМАЕТ ЗАЯВКИ</b>@endif</small><h3>{{ $team->name }}</h3><p>{{ \Illuminate\Support\Str::limit($team->description ?: 'Состав, игры и турнирная история команды.', 105) }}</p><strong>Открыть <i class="ti ti-arrow-right"></i></strong></div></a>
            @empty
                @if($hiringTeams->isEmpty())<div class="home-empty-card"><i class="ti ti-users-plus"></i><strong>Собери свою команду</strong><p>Создай название, добавь игроков и выходи постоянным составом.</p></div>@endif
            @endforelse
        </div>
        <div class="home-section-actions"><a class="btn btn--primary" href="{{ route('teams.index') }}">Смотреть команды <i class="ti ti-arrow-right"></i></a>@auth<a class="home-text-link" href="{{ route('teams.create') }}">Создать команду <i class="ti ti-plus"></i></a>@endauth</div>
    </div>
</section>

<section class="home-product-section home-product-section--tournament" id="tournaments">
    <div class="inner"><div class="home-tournament"><div class="home-tournament__copy"><p class="home-section-head__eyebrow"><i class="ti ti-trophy"></i> Турниры</p>@if($featuredTournament)<span class="home-tournament__status">{{ $currentTournament ? 'Сейчас проходит' : 'Ближайший турнир' }}</span><h2>{{ $featuredTournament->title }}</h2><p>{{ $featuredTournament->short_description ?: 'Участники, матчи, результаты и турнирная таблица в одном месте.' }}</p><div class="home-tournament__stats"><span><b>{{ $featuredTournament->entries_count }}</b> участников / команд</span><span><b>{{ $featuredTournament->matches_count }}</b> матчей</span><span><b>{{ $featuredTournament->starts_on?->format('d.m') }}</b> старт</span></div><div class="home-section-actions"><a class="btn btn--primary" href="{{ route('tournaments.show', $featuredTournament->routeIdentifier()) }}">Следить за турниром <i class="ti ti-arrow-right"></i></a><a class="home-text-link" href="{{ route('tournaments.index') }}">Все турниры</a></div>@else<span class="home-tournament__status">Соревнуйся</span><h2>Турнир — это не таблица в чате.</h2><p>Собирай участников, формируй команды, назначай матчи и веди результаты в одном месте.</p><div class="home-section-actions"><a class="btn btn--primary" href="{{ route('tournaments.index') }}">Смотреть турниры <i class="ti ti-arrow-right"></i></a></div>@endif</div><div class="home-tournament__visual"><img src="{{ $featuredTournament?->cover?->publicUrl() ?? asset('images/home-court.png') }}" alt=""><span></span><i class="ti ti-trophy"></i><b>MSKBA</b></div></div></div>
</section>

<section class="home-product-section home-product-section--identity" id="profile">
    <div class="inner home-identity"><div class="home-identity__copy"><p class="home-section-head__eyebrow"><i class="ti ti-user-star"></i> Твой профиль</p><h2>Твоя игра остаётся с тобой.</h2><p>Профиль связывает игры, команды, статистику, фотографии и достижения. Отдельные встречи превращаются в твою баскетбольную историю.</p>@auth<a class="btn btn--primary" href="{{ route('account') }}">Открыть мой профиль <i class="ti ti-arrow-right"></i></a>@else<button class="btn btn--primary js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('account', [], false) }}">Создать профиль <i class="ti ti-arrow-right"></i></button>@endauth</div><div class="home-identity__flow"><div><i class="ti ti-user"></i><strong>Профиль</strong><small>ты и твоя роль</small></div><span>→</span><div><i class="ti ti-ball-basketball"></i><strong>Игры</strong><small>участие и результаты</small></div><span>→</span><div><i class="ti ti-chart-bar"></i><strong>Статистика</strong><small>история на площадке</small></div></div></div>
</section>

@if($latestNews->isNotEmpty())
<section class="home-product-section" id="news"><div class="inner"><header class="home-section-head"><div><p class="home-section-head__eyebrow"><i class="ti ti-news"></i> Новости</p><h2>Последнее в MSKBA</h2></div><a class="home-text-link" href="{{ route('news.index') }}">Все новости <i class="ti ti-arrow-right"></i></a></header><div class="home-news-grid">@foreach($latestNews as $news)<a class="home-news-card" href="{{ $news->destinationUrl() }}">@if($news->cover->first())<img src="{{ $news->cover->first()->publicUrl() }}" alt="">@endif<div><small>{{ $news->feed_published_at?->setTimezone($timezone)->translatedFormat('j F Y') }}</small><h3>{{ $news->title }}</h3>@if($news->short_description)<p>{{ \Illuminate\Support\Str::limit($news->short_description, 125) }}</p>@endif<strong>Читать <i class="ti ti-arrow-up-right"></i></strong></div></a>@endforeach</div></div></section>
@endif

<section class="home-final-cta"><div class="inner"><div class="home-final-cta__panel"><div><p class="home-section-head__eyebrow">Пора на площадку</p><h2>Готов играть?</h2><p>Посмотри ближайшие игры или создай свою. Всё остальное можно определить по ходу.</p></div><div><button class="btn btn--primary btn--lg js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-event-flow" data-modal-section="search">Игры и тренировки <i class="ti ti-arrow-right"></i></button><button class="btn btn--secondary btn--lg js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="home-venue-flow" data-modal-section="search">Площадки</button></div></div></div></section>

@component('theme::partials.modal.layout', ['id' => 'home-event-flow', 'dialogClass' => 'home-flow-modal__dialog'])
    <div class="home-flow-modal" data-home-flow="event">
        <p class="home-flow-modal__eyebrow">Игры и тренировки</p><h2 class="modal_title" id="modal-title-home-event-flow">Что хотите сделать?</h2>
        <div class="home-flow-modal__tabs"><button type="button" data-home-flow-tab="search">Найти</button><button type="button" data-home-flow-tab="create">Создать</button></div>
        <section data-home-flow-panel="search">
            <div class="home-flow-steps"><span class="is-active">1 · Тип</span><span>2 · Где</span><span>3 · Когда</span></div>
            <div class="home-flow-type-grid"><button type="button"><i class="ti ti-ball-basketball"></i>Игра</button><button type="button"><i class="ti ti-barbell"></i>Тренировка</button><button type="button"><i class="ti ti-users-group"></i>Игровая тренировка</button><button type="button"><i class="ti ti-trophy"></i>Турнир</button></div>
            <label class="home-flow-field"><span>Локация</span><div><i class="ti ti-map-pin"></i><input type="text" placeholder="Район, адрес, площадка или метро"><button type="button"><i class="ti ti-map-2"></i> Карта</button></div></label>
            <div class="home-flow-row"><label class="home-flow-field"><span>Дата от</span><input type="date"></label><label class="home-flow-field"><span>Дата до</span><input type="date"></label><button type="button" class="home-flow-filter"><i class="ti ti-train"></i> Метро</button></div>
            <div class="home-flow-modal__actions"><a class="btn btn--primary" href="{{ route('events.index') }}">Открыть каталог</a><button type="button" class="btn btn--secondary" data-home-flow-tab="create">Создать мероприятие</button></div>
            <p class="home-flow-note">Это заготовка нового wizard. Предикативный поиск, карта, метро и диапазон дат подключим следующим шагом.</p>
        </section>
        <section data-home-flow-panel="create" hidden>
            <div class="home-flow-steps"><span>1 · Поиск</span><span class="is-active">2 · Создание</span></div><p class="home-flow-intro">Выберите тип — дальше откроется уже существующий wizard создания с заполнением параметров мероприятия.</p>
            <div class="home-flow-create-grid"><a href="{{ route('events.create', ['type' => 'game']) }}"><i class="ti ti-ball-basketball"></i><strong>Игра</strong><small>Создать игру</small></a><a href="{{ route('events.create', ['type' => 'training']) }}"><i class="ti ti-barbell"></i><strong>Тренировка</strong><small>Создать тренировку</small></a><a href="{{ route('events.create', ['type' => 'game_training']) }}"><i class="ti ti-users-group"></i><strong>Игровая тренировка</strong><small>Создать игровую тренировку</small></a><a href="{{ route('tournaments.create') }}"><i class="ti ti-trophy"></i><strong>Турнир</strong><small>Создать турнир</small></a></div>
        </section>
    </div>
@endcomponent

@component('theme::partials.modal.layout', ['id' => 'home-venue-flow', 'dialogClass' => 'home-flow-modal__dialog'])
    <div class="home-flow-modal" data-home-flow="venue"><p class="home-flow-modal__eyebrow">Площадки</p><h2 class="modal_title" id="modal-title-home-venue-flow">Найти или добавить площадку</h2><div class="home-flow-modal__tabs"><button type="button" data-home-flow-tab="search">Найти</button><button type="button" data-home-flow-tab="create">Добавить</button></div><section data-home-flow-panel="search"><div class="home-flow-steps"><span class="is-active">1 · Поиск</span><span>2 · Карта</span><span>3 · Фильтры</span></div><label class="home-flow-field"><span>Площадка</span><div><i class="ti ti-search"></i><input type="text" placeholder="Название, адрес, район или метро"><button type="button"><i class="ti ti-map-2"></i> Карта</button></div></label><div class="home-flow-row"><button type="button" class="home-flow-filter"><i class="ti ti-train"></i> Метро</button><button type="button" class="home-flow-filter"><i class="ti ti-adjustments-horizontal"></i> Свойства</button></div><div class="home-flow-modal__actions"><a class="btn btn--primary" href="{{ route('venues') }}">Открыть каталог</a><button type="button" class="btn btn--secondary" data-home-flow-tab="create">Добавить площадку</button></div><p class="home-flow-note">Заготовка wizard: сюда подключим предикативный поиск, карту с точками и фильтр по метро.</p></section><section data-home-flow-panel="create" hidden><div class="home-flow-steps"><span>1 · Поиск</span><span class="is-active">2 · Добавление</span></div><p class="home-flow-intro">Создание площадки остаётся отдельным полноценным сценарием. На следующем шаге встроим его в этот wizard.</p><a class="btn btn--primary" href="{{ route('venues.create') }}">Перейти к добавлению площадки <i class="ti ti-arrow-right"></i></a></section></div>
@endcomponent
@endsection
