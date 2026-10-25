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
    $venueInterestUsers = (clone $activeVenueInterests)
        ->with(['user.profile.activeAvatar'])
        ->where('show_on_venue', true)
        ->orderByDesc('created_at')->get()
        ->unique('user_id')
        ->filter(function ($intent) use ($viewer): bool {
            $user = $intent->user;
            return $user !== null && ! $user->isBlocked()
                && app(\App\Modules\Identity\Application\Services\UserPrivacyAccessService::class)
                    ->allows($user, null, \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::PROFILE);
        })
        ->map(function ($intent) use ($viewer): array {
            $user = $intent->user;
            $canShowAvatar = app(\App\Modules\Identity\Application\Services\UserPrivacyAccessService::class)
                ->allows($user, null, \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::AVATAR);
            return [
                'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                'avatar' => $canShowAvatar ? $user->profile?->avatarUrl() : null,
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
                <span class="app-venue-favorite-action">
                    <button type="button" class="mskba-favorite-heart" data-favorite-venue="{{ $venue->id }}"
                        aria-pressed="false" aria-label="Добавить в избранное" title="Добавить в избранное">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.4c0 5-8.8 11.1-8.8 11.1S3.2 13.4 3.2 8.4a5.1 5.1 0 0 1 8.8-3.3 5.1 5.1 0 0 1 8.8 3.3Z"/></svg>
                    </button><span data-venue-favorite-count data-venue-favorite-count-id="{{ $venue->id }}">{{ $favoritesCount }}</span>
                </span>
                <a class="button secondary large" href="#app-venue-schedule">Расписание площадки</a>
            </div>
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
                <button type="button" class="button primary" data-venue-interest-action
                    data-interest-id="{{ $viewerVenueInterest?->id }}"
                    data-cancel-url="{{ $viewerVenueInterest ? route('play-intents.destroy', $viewerVenueInterest->id) : '' }}"
                    data-play-intent-open @disabled(!$canStartInterest)>
                    {{ $viewerHasVenueInterest ? 'Не хочу здесь играть' : 'Хочу здесь играть' }}
                </button>
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
                                                    @if(!empty($slot['eventUrl']))
                                                        <a href="{{ $slot['eventUrl'] }}" aria-label="Открыть мероприятие на {{ $slot['timeLabel'] ?? '' }}">{{ $slot['timeLabel'] ?? '' }}</a>
                                                    @else
                                                        <span>{{ $slot['timeLabel'] ?? '' }}</span>
                                                    @endif
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
                <span>{{ $player['name'] }}</span>
            </div>
        @empty
            <p class="muted">Пока нет игроков, разрешивших показывать свой профиль.</p>
        @endforelse
    </div>
    <p class="caption">Всего интересов: {{ $activeVenueInterestCount }}. Личные данные видны только с разрешения пользователей.</p>
    <button type="button" class="button primary" data-venue-interest-action data-interest-id="{{ $viewerVenueInterest?->id }}"
        data-cancel-url="{{ $viewerVenueInterest ? route('play-intents.destroy', $viewerVenueInterest->id) : '' }}"
        data-play-intent-open>{{ $viewerHasVenueInterest ? 'Не интересно' : 'Тоже хочу здесь играть' }}</button>
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
