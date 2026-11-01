@extends('theme::layouts.app', ['breadcrumbs' => [
    ['label' => 'Площадки', 'url' => route('venues')],
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
        <a class="button secondary" href="{{ route('venues') }}">К каталогу площадок</a>
    </div></section>
@else
@php
    $hero = $venue->featuredMedia[0]['url'] ?? null;
    $venueAddress = $venue->address?->display ?: $venue->rawAddress;
    $isConfirmedVenue = $venue->statusSlug === 'confirmed';
    $canStartInterest = $isConfirmedVenue;
    $viewer = auth()->user()?->canonical();
    $viewerVenueInterest = $viewer
        ? \App\Modules\PlayInterest\Domain\Models\PlayIntent::query()
            ->where('venue_id', $venue->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('user_id', $viewer->id)->orderByDesc('id')->first()
        : null;
    $viewerHasVenueInterest = $viewerVenueInterest !== null;
    $venueAudience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
        ->forVenue(\App\Modules\Venue\Domain\Models\Venue::query()->findOrFail($venue->id));
    $venueInterestUsers = collect(array_merge($venueAudience['direct'], $venueAudience['matched']));
    $activeVenueInterestCount = $venueAudience['total'];
    $venuePlayerWord = $activeVenueInterestCount % 100 >= 11 && $activeVenueInterestCount % 100 <= 14
        ? 'игроков'
        : (match ($activeVenueInterestCount % 10) { 1 => 'игрок', 2, 3, 4 => 'игрока', default => 'игроков' });
    $favoritesCount = class_exists(\App\Modules\Favorites\Domain\Models\Favorite::class)
        ? \App\Modules\Favorites\Domain\Models\Favorite::query()
            ->where('entity_type', 'venue')->where('entity_id', $venue->id)
            ->whereNull('trashed_at')->count()
        : 0;
    $environments = collect($venue->courts)->pluck('environment')->filter()->unique()->values();
    $areaLabel = $environments->contains('indoor') && $environments->contains('outdoor')
        ? 'уличная и крытая площадки'
        : ($environments->contains('indoor') ? 'крытая площадка'
            : ($environments->contains('outdoor') ? 'уличная площадка' : null));
    $selectedAreaLabel = $venue->selectedCourt['environment'] === 'indoor'
        ? 'крытая площадка'
        : ($venue->selectedCourt['environment'] === 'outdoor' ? 'уличная площадка' : null);
    $displayAreaLabel = $venue->isCourtPage ? $selectedAreaLabel : $areaLabel;
    $createEventUrl = route('events.wizard', array_filter([
        'venue_id' => $venue->id,
        'venue_court_id' => $venue->isCourtPage ? $venue->selectedCourt['id'] : null,
    ]));
    $scheduledDays = collect($venue->about->scheduleDays)->take(9);
    $activityDays = collect($venue->occupancyDays)
        ->filter(fn (array $day): bool => count($day['slots'] ?? []) > 0)
        ->values();
@endphp
<section class="container app-venue-page" aria-labelledby="app-venue-title">
    @include('components.entity-public-banner', ['bannerUrl' => \App\Modules\Venue\Domain\Models\Venue::query()->find($venue->id)?->bannerUrl()])
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
            <div class="app-venue-intro__court-heading">
                <p class="eyebrow accent">{{ $venue->type }}@if($displayAreaLabel): {{ $displayAreaLabel }}@endif</p>
                @if(count($venue->courts) > 1)
                    <details class="app-venue-court-picker" data-venue-court-dropdown>
                        <summary aria-label="Выбрать зал">{{ $venue->isCourtPage ? $venue->selectedCourt['name'] : 'Все залы' }}
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <nav aria-label="Залы площадки">
                            <a href="{{ route('venues.show', $venue->routeIdentifier()) }}" @if(!$venue->isCourtPage) aria-current="page" @endif>Все залы · общая страница</a>
                            @foreach($venue->courts as $court)
                                <a href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]) }}"
                                    @if($venue->isCourtPage && $venue->selectedCourt['id'] === $court['id']) aria-current="page" @endif>{{ $court['name'] }}</a>
                            @endforeach
                        </nav>
                    </details>
                @endif
            </div>
            <div class="app-entity-heading">
                <h1 id="app-venue-title">{{ $venue->name }}@if($venue->isCourtPage) <span class="app-venue-intro__court-name">· {{ $venue->selectedCourt['name'] }}</span>@endif</h1>
                @include('theme::partials.entity-edit-link', ['url' => $venue->canEdit ? route('account.venues.edit', $venue->routeIdentifier()) : null])
            </div>
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
                                style="--metro-color: {{ preg_match('/\A#?[0-9A-Fa-f]{6}\z/D', (string) $station->lineColor) ? '#'.ltrim((string) $station->lineColor, '#') : '#758392' }}"
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
                @if($viewerHasVenueInterest)
                    <a class="button primary large" href="{{ route('play-intents.mine') }}">Вы подписаны</a>
                @else
                    <button type="button" class="button primary large" data-play-intent-open data-venue-interest-action
                        @disabled(!$canStartInterest)>Хочу здесь играть</button>
                @endif
                <a class="button secondary large" href="#app-venue-schedule">Расписание</a>
                @if($groupOffersManagementUrl ?? null)
                    <a class="button secondary large" href="{{ $groupOffersManagementUrl }}">Управление промокодами</a>
                @endif
            </div>
        </div>
    </div>

    <nav class="app-venue-page__anchors" aria-label="Разделы площадки" data-venue-section-nav>
        <a href="#app-venue-activities">Игры</a>
        <a href="#app-venue-interest">Подписки игроков</a>
        <a href="#app-venue-schedule">Расписание</a>
        <a href="#app-venue-about">О площадке</a>
    </nav>

    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            <section class="panel app-venue-block" id="app-venue-activities">
                <header class="app-venue-block__header"><h2>Игры и мероприятия</h2></header>
                <p class="muted">Запланированные события и занятые интервалы на ближайшие девять дней.</p>
                @if($activityDays->isNotEmpty())
                    <div class="app-venue-activities__days">
                        @foreach($activityDays as $day)
                            <div class="app-venue-activities__day">
                                <h3>{{ $day['label'] ?? $day['date'] }}</h3>
                                <ul class="app-venue-activities__list">
                                    @foreach($day['slots'] as $slot)
                                        @php
                                            $isPublicEvent = !empty($slot['eventUrl']);
                                            $isPastEvent = $isPublicEvent && !empty($slot['isPastEvent']);
                                        @endphp
                                        <li class="app-venue-activities__item">
                                            <button type="button" @class(['app-venue-activity', 'app-venue-activity--past' => $isPastEvent]) data-venue-slot-open
                                                @if($isPastEvent) data-event-past="true" @endif
                                                data-slot-day="{{ $day['label'] ?? $day['date'] }}"
                                                data-slot-time="{{ $slot['timeLabel'] ?? '' }}"
                                                data-event-type="{{ $isPublicEvent ? ($slot['eventTypeLabel'] ?? 'Мероприятие') : 'Занятое время' }}"
                                                data-event-title="{{ $slot['eventTitle'] ?? '' }}"
                                                data-event-url="{{ $slot['eventUrl'] ?? '' }}"
                                                data-event-join-url="{{ $slot['eventJoinUrl'] ?? '' }}"
                                                data-event-join-method="{{ $slot['eventJoinMethod'] ?? 'PATCH' }}"
                                                data-can-join="{{ !empty($slot['canJoin']) ? 'true' : 'false' }}"
                                                data-join-state="{{ $slot['joinState'] ?? 'unavailable' }}"
                                                data-can-respond-tentative="{{ !empty($slot['canRespondTentative']) ? 'true' : 'false' }}"
                                                aria-label="Подробнее: {{ $isPublicEvent ? ($slot['eventTitle'] ?: $slot['eventTypeLabel']) : 'Занятое время' }}, {{ $day['label'] ?? $day['date'] }}, {{ $slot['timeLabel'] ?? '' }}">
                                                <span class="app-venue-activity__meta">
                                                    <span>{{ $slot['timeLabel'] ?? '' }}</span>
                                                    <span class="app-venue-activity__type">{{ $isPublicEvent ? ($slot['eventTypeLabel'] ?? 'Мероприятие') : 'Занятое время' }}</span>
                                                </span>
                                                <strong class="app-venue-activity__title">{{ $isPublicEvent ? ($slot['eventTitle'] ?: 'Мероприятие') : 'Время занято' }}</strong>
                                                @if($isPastEvent)<span class="sr-only">Мероприятие завершилось.</span>@endif
                                                @if($isPublicEvent && !empty($slot['eventDescription']))
                                                    <span class="app-venue-activity__description">{{ $slot['eventDescription'] }}</span>
                                                @endif
                                                @if($isPublicEvent)
                                                    <span class="app-venue-activity__counts">
                                                        <span>Подтвердили: <b>{{ $slot['eventConfirmedCount'] ?? 0 }}</b></span>
                                                        <span>Думают: <b>{{ $slot['eventTentativeCount'] ?? 0 }}</b></span>
                                                    </span>
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="muted">Запланированных занятых слотов на ближайшие девять дней пока нет.</p>
                @endif
                <a href="{{ route('events.index') }}" class="button secondary app-venue-activities__all">Смотреть мероприятия <svg aria-hidden="true"><use href="#arrow"/></svg></a>
            </section>
            <section class="panel app-venue-block" id="app-venue-interest">
                <header class="app-venue-block__header"><h2>Площадкой интересуются</h2></header>
                <div class="app-venue-interest-summary" data-play-venue-interest-summary>
                    <div class="app-venue-interest-summary__profiles">
                        <div class="app-venue-interest-summary__avatars" aria-label="Игроки с подписками на площадку">
                            @foreach($venueInterestUsers->take(5) as $player)
                                <span class="app-venue-interest-summary__avatar" title="{{ $player['is_self'] ? 'Это вы' : $player['name'] }}">
                                    @if(!$player['public'] && !$player['is_self'])
                                        <svg class="app-venue-interest-summary__profile-icon" aria-hidden="true"><use href="#user"/></svg>
                                    @elseif($player['avatar'])
                                        <img src="{{ $player['avatar'] }}" alt="{{ $player['is_self'] ? 'Это вы' : $player['name'] }}" loading="lazy">
                                    @else
                                        <span aria-hidden="true">{{ mb_strtoupper(mb_substr($player['name'], 0, 1)) }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                        <strong class="app-venue-interest-summary__count">{{ $activeVenueInterestCount }} {{ $venuePlayerWord }}</strong>
                        <button type="button" class="button secondary" data-venue-interests-open>Посмотреть всех</button>
                    </div>
                </div>
            </section>
            <section class="panel app-venue-block" id="app-venue-schedule">
                <header class="app-venue-block__header"><h2>Расписание</h2></header>
                <p class="muted">Рабочие часы площадки и исключения из обычного графика.</p>
                @if($scheduledDays->isNotEmpty())
                    <div class="app-venue-schedule">
                        @foreach($scheduledDays as $day)
                            <div class="app-venue-schedule__day">
                                <strong>{{ $day['label'] ?? $day['date'] }}</strong>
                                @if(count($day['intervals'] ?? []))
                                    <p class="app-venue-schedule__hours" aria-label="Часы работы">
                                        <svg aria-hidden="true"><use href="#clock"/></svg>
                                        <span>@foreach($day['intervals'] as $hours){{ $hours['startsAt'] }}–{{ $hours['endsAt'] }}@unless($loop->last), @endunless @endforeach</span>
                                    </p>
                                @else
                                    <p class="app-venue-schedule__closed">Не работает</p>
                                @endif
                                @if(!empty($day['isException']))
                                    <span class="app-venue-schedule__exception">{{ !empty($day['isClosed']) ? 'Исключение: выходной' : 'Исключение: изменённые часы' }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="muted">Расписание работы площадки ещё не установлено.</p>
                @endif
                @unless($venue->isFreeAccess)
                    <p class="caption">Часы работы не гарантируют доступность бронирования. Уточни свободное время и стоимость при оформлении.</p>
                @endunless
            </section>
            <section class="panel app-venue-block" id="app-venue-about">
                <header class="app-venue-block__header"><h2>{{ $venue->isCourtPage ? 'О зале / зоне' : 'О площадке' }}</h2></header>
                @if($venue->isCourtPage)
                    @php
                        $policy = $venue->effectiveCourtPolicy;
                    @endphp
                    <dl class="app-venue-court-properties">
                        <div><dt>Состояние</dt><dd>{{ $policy['is_open'] ? 'Работает' : 'Временно закрыт' }}</dd></div>
                        <div><dt>Стоимость</dt><dd>{{ $policy['requires_payment'] ? 'Платно' : 'Бесплатно' }}</dd></div>
                        <div><dt>Запись</dt><dd>{{ $policy['requires_booking'] ? 'Необходимо бронирование' : 'Свободный доступ' }}</dd></div>
                        <div><dt>Подтверждение</dt><dd>{{ $policy['requires_booking_approval'] ? 'Администратором' : 'Автоматическое' }}</dd></div>
                        @if($policy['currency'] !== null && $policy['whole_price_per_step_minor'] !== null)
                            <div><dt>Базовая цена всего зала за {{ $policy['time_step_minutes'] }} мин.</dt>
                                <dd>{{ number_format($policy['whole_price_per_step_minor'] / 100, 2, ',', ' ') }} {{ $policy['currency'] }}</dd></div>
                        @endif
                        @if($policy['currency'] !== null && $policy['half_price_per_step_minor'] !== null)
                            <div><dt>Базовая цена половины за {{ $policy['time_step_minutes'] }} мин.</dt>
                                <dd>{{ number_format($policy['half_price_per_step_minor'] / 100, 2, ',', ' ') }} {{ $policy['currency'] }}</dd></div>
                        @endif
                        <div><dt>Зал / зона</dt><dd>{{ $venue->selectedCourt['name'] }}</dd></div>
                        <div><dt>Количество колец</dt><dd>{{ $venue->selectedCourt['hoopsCount'] }}</dd></div>
                        <div><dt>Покрытие</dt><dd>{{ $venue->selectedCourt['surfaceLabel'] ?: 'Не указано' }}</dd></div>
                        <div><dt>Бронирование целиком</dt><dd>{{ $venue->selectedCourt['allowsWhole'] ? 'Разрешено' : 'Недоступно' }}</dd></div>
                        <div><dt>Бронирование половинами</dt><dd>{{ $venue->selectedCourt['supportsHalves'] && $venue->selectedCourt['allowsHalves'] ? 'Разрешено' : 'Недоступно' }}</dd></div>
                    </dl>
                @else
                    <p class="app-venue-court-overview"><strong>Количество залов:</strong> {{ count($venue->courts) }}</p>
                @endif
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
            <strong>Хочешь здесь играть?</strong>
            <p>Укажи время и подходящий формат. MSKBA сохранит твою подписку без обязательств.</p>
            @if($viewerHasVenueInterest)
                <a class="button primary full" href="{{ route('play-intents.mine') }}">Вы подписаны</a>
            @else
                <button type="button" class="button primary full" data-play-intent-open data-venue-interest-action
                    @disabled(!$canStartInterest)>Хочу здесь играть</button>
            @endif
            <a class="button secondary full" href="{{ $createEventUrl }}" data-venue-create-event-link>Создать мероприятие</a>
        </aside>
    </div>
</section>
@include('theme::partials.venue-map-dialog', ['mapVenue' => $venue])
<dialog class="app-venue-overlay" data-venue-interests-dialog aria-label="Игроки, заинтересованные в площадке">
    <div class="app-venue-overlay__header"><h2>Площадкой интересуются</h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button></div>
    <div class="app-venue-interests-dialog__total" aria-label="{{ $activeVenueInterestCount }} {{ $venuePlayerWord }}">
        <strong class="app-venue-interests-dialog__total-number">{{ $activeVenueInterestCount }}</strong>
        <span class="app-venue-interests-dialog__total-word">{{ $venuePlayerWord }}</span>
    </div>
    @include('theme::pages.venues.partials.interest-users-list', ['venueAudience' => $venueAudience])
    <a class="button secondary" href="{{ route('venues.interest-group', ['venue' => $venue->id]) }}">Перейти на страницу группы</a>
    @if($viewerHasVenueInterest)
        <a class="button primary" href="{{ route('play-intents.mine') }}">Вы подписаны</a>
    @else
        <button type="button" class="button primary" data-venue-interest-action data-play-intent-open>Тоже хочу здесь играть</button>
    @endif
</dialog>
<dialog class="app-venue-overlay app-venue-player-details-dialog" data-venue-player-details-dialog aria-labelledby="app-venue-player-details-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-player-details-title">Игровая информация</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button>
    </div>
    <div class="app-venue-player-details__body" data-venue-player-details-body></div>
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
                <input type="hidden" name="_method" value="PATCH" data-venue-slot-join-method>
                <input type="hidden" name="status" value="confirmed">
                <button class="button primary" type="submit" data-venue-slot-join-label>Присоединиться</button>
            </form>
            <form method="POST" data-venue-slot-tentative-form hidden>
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="tentative">
                <button class="button secondary" type="submit">Думаю</button>
            </form>
        @else
            <a class="button primary" data-venue-slot-guest-link hidden>Присоединиться</a>
        @endauth
    </div>
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
            'venuesSearch' => route('venues'),
            'mine' => route('play-intents.mine'),
            'favoriteStatuses' => route('favorites.venue-statuses'),
            'favoriteToggle' => route('favorites.toggle'),
        ],
    ];
@endphp
<div data-mskba-play-intent-root data-options='@json($playIntentOptions)'></div>
@endif
@endsection
