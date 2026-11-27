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
    $venueAudience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
        ->forVenue(\App\Modules\Venue\Domain\Models\Venue::query()->findOrFail($venue->id));
    $venueInterestUsers = collect(array_merge($venueAudience['direct'], $venueAudience['matched']))
        ->sortByDesc(fn (array $player): bool => filled($player['avatar']))->values();
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
        ? 'крытый зал и уличный корт'
        : ($environments->contains('indoor') ? 'крытый зал'
            : ($environments->contains('outdoor') ? 'уличный корт' : null));
    $selectedAreaLabel = $venue->selectedCourt['environment'] === 'indoor'
        ? 'крытый зал'
        : ($venue->selectedCourt['environment'] === 'outdoor' ? 'уличный корт' : null);
    $displayAreaLabel = $venue->isCourtPage ? $selectedAreaLabel : $areaLabel;
    $isOutdoorCourt = $venue->isCourtPage && $venue->selectedCourt['environment'] === 'outdoor';
    $unitNoun = $isOutdoorCourt ? 'корт' : 'зал';
    $createEventUrl = route('events.wizard', array_filter([
        'venue_id' => $venue->id,
        'venue_court_id' => $venue->isCourtPage ? $venue->selectedCourt['id'] : null,
    ]));
    $scheduledDays = collect($venue->about->scheduleDays)->take(9);
    // The event area is not the booking-occupancy feed. Only
    // bookings with an actual attached event belong here.
    // Public upcoming events without a held rental must be visible on the
    // venue page too; occupancyDays only reflects occupied booking slots.
    $plannedVenueEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->where('venue_id', $venue->id)
        ->where('status', 'published')->where('visibility', 'public')
        ->where('booking_deferred', true)->whereNull('booking_id')
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')->limit(15)->get();
    $activityDays = collect($venue->occupancyDays)
        ->map(function (array $day): array {
            $day['slots'] = collect($day['slots'] ?? [])
                ->filter(fn (array $slot): bool => !empty($slot['hasEvent']))
                ->values()
                ->all();
            return $day;
        })
        ->filter(fn (array $day): bool => count($day['slots']) > 0)
        ->values();
    $venuePromotionResolver = app(\App\Modules\Promotions\Application\Queries\ResolveActivePromotions::class);
    $venueAboutPromotions = collect($venuePromotionResolver->forVenueDetails(
        (int) $venue->id, $venue->courtSchedules,
    ));
@endphp
@php
    $bannerVenue = \App\Modules\Venue\Domain\Models\Venue::query()->find($venue->id);
    $canReviewVenue = $bannerVenue?->isPubliclyVisible() ?? false;
@endphp
<section class="container app-venue-page" aria-labelledby="app-venue-title">
    @include('components.entity-public-banner', [
        'bannerUrl' => $bannerVenue?->bannerUrl(),
        'bannerMode' => $bannerVenue?->banner_display_mode,
        'overlayCss' => $bannerVenue?->bannerOverlayCss(),
    ])
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
                        <summary aria-label="Выбрать зал или корт">{{ $venue->isCourtPage ? $venue->selectedCourt['name'] : 'Вся площадка' }}
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <nav aria-label="Залы и корты площадки">
                            <a href="{{ route('venues.show', $venue->routeIdentifier()) }}" @if(!$venue->isCourtPage) aria-current="page" @endif>Вся площадка</a>
                            @foreach($venue->courts as $court)
                                <a href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]) }}"
                                    @if($venue->isCourtPage && $venue->selectedCourt['id'] === $court['id']) aria-current="page" @endif>{{ $court['name'] }}</a>
                            @endforeach
                        </nav>
                    </details>
                @endif
            </div>
            <div class="app-entity-heading">
                <h1 id="app-venue-title">@if($venue->isCourtPage)<a class="app-venue-parent-link" href="{{ route('venues.show', $venue->routeIdentifier()) }}">{{ $venue->name }}</a>@else{{ $venue->name }}@endif</h1>
                @php
                    $venueRating = $venue->about->rating;
                    $venueRatingLabel = $venueRating === null ? 'Пока нет оценок игроков' : 'Рейтинг площадки: '.number_format($venueRating, 1, '.', '').' из 5 · оценок: '.($venue->about->ratingCount ?? 0);
                @endphp
                <button type="button" class="app-venue-intro__rating{{ $venueRating === null ? ' is-unrated' : '' }}" data-venue-heading-rating @if($canReviewVenue) data-venue-review-open @else disabled @endif
                      title="{{ $venueRatingLabel }}" aria-label="Оценить площадку. {{ $venueRatingLabel }}" aria-haspopup="dialog">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <defs><linearGradient id="venue-rating-fill"><stop data-review-fill offset="{{ ($venueRating ?? 0) * 20 }}%" stop-color="var(--accent)"/><stop data-review-fill offset="{{ ($venueRating ?? 0) * 20 }}%" stop-color="transparent"/></linearGradient></defs>
                        <path d="m12 2.8 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3L12 17.2l-5.6 3 1.1-6.3-4.6-4.5 6.3-.9L12 2.8Z" fill="url(#venue-rating-fill)"/>
                    </svg>
                    <span data-review-heading-value aria-hidden="true" @if($venueRating === null) hidden @endif>{{ $venueRating === null ? '' : number_format($venueRating, 1, '.', '') }}</span>
                </button>
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
            @if(count($venue->amenities))
                <div class="app-venue-intro__badges">
                    @foreach(array_slice($venue->amenities, 0, 7) as $amenity)
                        <span class="badge">{{ $amenity->name }}</span>
                    @endforeach
                    @if(count($venue->amenities) > 7)
                        <a href="#app-venue-about" class="app-venue-intro__amenities-more">и другие</a>
                    @endif
                </div>
            @endif
            @if($venue->shortDescription || $venue->isCourtPage)
                <p class="app-venue-intro__copy">@if($venue->isCourtPage)<strong>{{ \Illuminate\Support\Str::ucfirst($venue->selectedCourt['name']) }}</strong>@if($venue->shortDescription): @endif @endif{{ $venue->shortDescription }}</p>
            @endif
            <div class="app-venue-intro__actions">
                <button type="button" class="button primary large" data-venue-play-open>Хочу здесь играть</button>
                <button type="button" class="button secondary large" data-venue-favorite-main data-favorite-count="{{ $favoritesCount }}" aria-pressed="false"><svg class="venue-favorite-follow__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg><span data-venue-favorite-main-label>В избранное@if($favoritesCount > 0) ({{ $favoritesCount }})@endif</span></button>
            </div>
        </div>
    </div>

    <nav class="app-venue-page__anchors" aria-label="Разделы площадки" data-venue-section-nav>
        @if($activityDays->isNotEmpty() || $plannedVenueEvents->isNotEmpty())
            <a href="#app-venue-activities">Игры</a>
        @endif
        <a href="#app-venue-interest" @if($activeVenueInterestCount === 0) hidden @endif>Интересы игроков</a>
        <a href="#app-venue-about">{{ $venue->isCourtPage ? ($isOutdoorCourt ? 'О корте' : 'О зале') : 'О площадке' }}</a>
        <a href="#app-venue-schedule">Расписание</a>
        <a href="#app-venue-reviews">Отзывы</a>
        @if(is_numeric($venue->address?->latitude) && is_numeric($venue->address?->longitude))
            <a href="#app-venue-nearby">Площадки рядом</a>
        @endif
    </nav>

    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            @if($plannedVenueEvents->isNotEmpty())
            <section class="panel app-venue-block" id="{{ $activityDays->isEmpty() ? 'app-venue-activities' : 'app-venue-planned-events' }}">
                <header class="app-venue-block__header"><h2>Предстоящие мероприятия</h2></header>
                <ul class="app-venue-activities__list">
                    @foreach($plannedVenueEvents as $planned)
                        @php
                            $timeZone = $planned->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
                            $localTime = $planned->starts_at->setTimezone($timeZone);
                        @endphp
                        <li class="app-venue-activities__item">
                            <a class="app-venue-activity" href="{{ route('events.show', $planned->routeIdentifier()) }}"
                                data-venue-play-open-event="{{ $planned->id }}" aria-haspopup="dialog">
                                <span class="app-venue-activity__meta">
                                    <span>{{ $localTime->format('d.m.Y H:i') }}</span>
                                    <span class="app-venue-activity__type">{{ $planned->type->label() }}</span>
                                </span>
                                <strong class="app-venue-activity__title">{{ $planned->title }}</strong>
                                <span class="app-venue-activity__description">Сбор участников</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
            @endif

            @if($activityDays->isNotEmpty())
            <section class="panel app-venue-block" id="app-venue-activities">
                <header class="app-venue-block__header"><h2>Игры и мероприятия</h2></header>
                <p class="muted">Мероприятия на площадке в ближайшие девять дней.</p>
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
                                                data-has-event="true" data-event-type="{{ $slot['eventTypeLabel'] ?? 'Мероприятие' }}"
                                                data-event-title="{{ $slot['eventTitle'] ?? '' }}"
                                                data-event-url="{{ $slot['eventUrl'] ?? '' }}"
                                                data-event-join-url="{{ $slot['eventJoinUrl'] ?? '' }}"
                                                data-event-join-method="{{ $slot['eventJoinMethod'] ?? 'PATCH' }}"
                                                data-can-join="{{ !empty($slot['canJoin']) ? 'true' : 'false' }}"
                                                data-join-state="{{ $slot['joinState'] ?? 'unavailable' }}"
                                                data-can-respond-tentative="{{ !empty($slot['canRespondTentative']) ? 'true' : 'false' }}"
                                                aria-label="Подробнее: {{ $isPublicEvent ? ($slot['eventTitle'] ?: $slot['eventTypeLabel']) : 'Мероприятие по приглашению' }}, {{ $day['label'] ?? $day['date'] }}, {{ $slot['timeLabel'] ?? '' }}">
                                                <span class="app-venue-activity__meta">
                                                    <span>{{ $slot['timeLabel'] ?? '' }}</span>
                                                    <span class="app-venue-activity__type">{{ $isPublicEvent ? ($slot['eventTypeLabel'] ?? 'Мероприятие') : 'По приглашению' }}</span>
                                                </span>
                                                <strong class="app-venue-activity__title">{{ $isPublicEvent ? ($slot['eventTitle'] ?: 'Мероприятие') : 'Мероприятие' }}</strong>
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
                <a href="{{ route('events.index') }}" class="button secondary app-venue-activities__all">Смотреть мероприятия <svg aria-hidden="true"><use href="#arrow"/></svg></a>
            </section>
            @endif
            <section class="panel app-venue-block" id="app-venue-interest" @if($activeVenueInterestCount === 0) hidden @endif>
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
            @php
                $bookableCourtIds = collect($venue->courtSchedules)->where('bookingEnabled', true)->pluck('id')->all();
            @endphp
            <section class="panel app-venue-block" id="app-venue-about">
                <header class="app-venue-block__header"><h2>{{ $venue->isCourtPage ? ($isOutdoorCourt ? 'О корте' : 'О зале') : 'О площадке' }}</h2></header>
                @if($venue->isCourtPage)
                    @php
                        $policy = $venue->effectiveCourtPolicy;
                        $court = $venue->selectedCourt;
                        $conditionLabel = static fn (?int $value): ?string => match ($value) {
                            1 => 'Очень плохое', 2 => 'Плохое', 3 => 'Удовлетворительное',
                            4 => 'Хорошее', 5 => 'Отличное', default => null,
                        };
                        $properties = [
                            ['sliders', 'Покрытие', $court['surfaceLabel']],
                            ['sliders', 'Состояние покрытия', $conditionLabel($court['surfaceCondition'])],
                            ['file-description', 'Разметка', $court['markingLabel']],
                            ['ball', 'Количество колец', $court['hoopsCount']],
                            ['ball', 'Состояние колец', $conditionLabel($court['hoopsCondition'])],
                            ['arrow', 'Размеры', $court['lengthMeters'] > 0 && $court['widthMeters'] > 0
                                ? (float) $court['lengthMeters'].' × '.(float) $court['widthMeters'].' м' : null],
                            [$policy['is_open'] ? 'check' : 'close', 'Состояние', $policy['is_open'] ? 'Работает' : 'Временно закрыт'],
                            ['currency-ruble', 'Стоимость', $policy['requires_payment'] ? 'Платно' : 'Бесплатно'],
                            ['calendar', 'Доступ', $policy['requires_booking'] ? 'Необходимо бронирование' : 'Свободный доступ'],
                        ];
                        if ($policy['requires_booking']) {
                            $properties[] = ['check', 'Подтверждение', $policy['requires_booking_approval'] ? 'Администратором' : 'Автоматическое'];
                        }
                        if ($policy['currency'] !== null && $policy['whole_price_per_step_minor'] !== null) {
                            $properties[] = ['currency-ruble', 'Базовая цена всего '.$unitNoun.'а за '.$policy['time_step_minutes'].' мин.',
                                number_format($policy['whole_price_per_step_minor'] / 100, 2, ',', ' ').' '.$policy['currency']];
                        }
                        if ($policy['currency'] !== null && $policy['half_price_per_step_minor'] !== null) {
                            $properties[] = ['currency-ruble', 'Базовая цена половины за '.$policy['time_step_minutes'].' мин.',
                                number_format($policy['half_price_per_step_minor'] / 100, 2, ',', ' ').' '.$policy['currency']];
                        }
                    @endphp
                    <dl class="app-venue-court-properties">
                        @foreach($properties as [$icon, $label, $value])
                            @if($value !== null && $value !== '')
                                <div><svg aria-hidden="true"><use href="#{{ $icon }}"/></svg><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                @else
                    @if(count($venue->courts))
                        <ul class="app-venue-court-overview">
                            @foreach($venue->courts as $court)
                                <li>
                                    <a class="app-venue-court-link" href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]) }}">{{ $court['name'] }}</a>
                                    <span class="app-venue-court-overview__separator" aria-hidden="true">|</span>
                                    <span>{{ \App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum::tryFrom($court['environment'] ?? '')?->label() ?? 'Тип не указан' }}</span>
                                    <span class="app-venue-court-overview__separator" aria-hidden="true">|</span>
                                    <span class="app-venue-court-overview__status" data-operational="{{ $court['isOpen'] ? 'active' : 'inactive' }}"><svg aria-hidden="true"><use href="#{{ $court['isOpen'] ? 'check' : 'close' }}"/></svg>{{ $court['isOpen'] ? 'Работает' : 'Не работает' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
                @if(trim((string) $venue->fullDescription) !== '')
                    <div class="app-venue-block__description app-venue-description">
                        {!! app(\App\Modules\Content\Application\Services\ContentBodySanitizer::class)->sanitize((string) $venue->fullDescription) !!}
                    </div>
                @endif
                @if($venueAboutPromotions->isNotEmpty())
                    <div class="app-venue-about__promotions" aria-label="Акции площадки">
                        @foreach($venueAboutPromotions as $promotion)
                            <div class="app-venue-about__promotion" aria-label="{{ $promotion['title'] }}">
                                <strong>{{ $promotion['title'] }}</strong>
                                @if($promotion['description'])<p>{{ $promotion['description'] }}</p>@endif
                                @if($promotion['amount_caption'])
                                    <span class="app-venue-promotion__amount">{{ $promotion['amount_caption'] }}</span>
                                @endif
                                @if($promotion['cta_url'])
                                    <a href="{{ $promotion['cta_url'] }}" class="button secondary">{{ $promotion['cta_label'] ?: 'Подробнее' }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @if(count($venue->amenities))
                    <h3>Удобства</h3>
                    <div class="app-venue-block__amenities">
                        @foreach($venue->amenities as $amenity)
                            <span class="badge">{{ $amenity->name }}</span>
                        @endforeach
                    </div>
                @endif
            </section>
            @include('theme::pages.venues.schedule-fragment')
            @include('theme::pages.venues.partials.reviews')
            @if(is_numeric($venue->address?->latitude) && is_numeric($venue->address?->longitude))
                @php
                    $nearbyHandler = app(\App\Modules\Venue\Application\UseCases\FindNearbyVenuesHandler::class);
                    $similarMode = request('nearby') === 'similar';
                    $criteriaLabels = [
                        'type' => 'Тип площадки',
                        'environment' => 'Крытый зал или уличный корт',
                        'payment' => 'Платный или бесплатный доступ',
                        'requires_booking' => 'Необходимость предварительной записи',
                        'requires_booking_approval' => 'Подтверждение администратором',
                        'surface' => 'Тип покрытия',
                        'amenities' => 'Удобства',
                        'dimensions' => 'Размеры игровой зоны',
                        'hoops' => 'Количество баскетбольных колец',
                    ];
                    $criteria = array_values(array_intersect(array_keys($criteriaLabels),
                        (array) request('criteria', ['type', 'environment', 'payment'])));
                    $lat = (float) $venue->address->latitude;
                    $lon = (float) $venue->address->longitude;
                    $nearby = $similarMode
                        ? $nearbyHandler->similar($venue->id, $lat, $lon,
                            $venue->isCourtPage ? (int) $venue->selectedCourt['id'] : null, $criteria)
                        : $nearbyHandler->handle($venue->id, $lat, $lon, 6, 10);
                    if (! $similarMode && $nearby === []) {
                        $nearby = $nearbyHandler->handle($venue->id, $lat, $lon, 3);
                    }
                    // Reuse the same DTOs and card partial as the venue catalog.
                    // Restrict the query to matched IDs, not the full catalog.
                    $nearbyCards = collect(app(\App\Modules\Venue\Application\UseCases\ListVenuesHandler::class)
                        ->handle(null, null, [], null, array_column($nearby, 'id')))->keyBy('id');
                @endphp
                <section class="panel app-venue-block" id="app-venue-nearby" data-venue-map-reference-id="{{ $venue->id }}">
                    <header class="app-venue-block__header"><h2>Площадки рядом</h2></header>
                    <div class="app-venue-nearby__toolbar" aria-label="Режим подбора площадок">
                        <a class="button {{ ! $similarMode ? 'primary' : 'secondary' }}"
                            href="{{ request()->fullUrlWithQuery(['nearby' => 'all']) }}#app-venue-nearby"
                            @if(! $similarMode) aria-current="true" @endif>Все</a>
                        <a class="button {{ $similarMode ? 'primary' : 'secondary' }}"
                            href="{{ request()->fullUrlWithQuery(['nearby' => 'similar']) }}#app-venue-nearby"
                            @if($similarMode) aria-current="true" @endif>Похожие</a>
                        @if($similarMode)
                            <button type="button" class="button secondary" data-venue-nearby-criteria-open>
                                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/></svg>
                                Критерии сходства
                            </button>
                        @endif
                    </div>
                    @if($nearby !== [])
                        <div class="app-venues-catalog__items app-venue-nearby__cards">
                            @foreach($nearby as $item)
                                @if($nearbyCards->has($item['id']))
                                    @include('theme::pages.venues.partials.index-card', [
                                        'venue' => $nearbyCards->get($item['id']),
                                        'cardUrl' => $item['url'],
                                        'distanceMeters' => $item['distance_meters'],
                                        'distanceOriginName' => $venue->name,
                                        'cardCourtName' => $item['court_name'] ?? null,
                                    ])
                                @endif
                            @endforeach
                        </div>
                    @else
                        <p class="muted">{{ $similarMode
                            ? 'Похожие площадки не найдены. Измените критерии сходства.'
                            : 'Другие площадки не найдены.' }}</p>
                    @endif
                </section>
                @if($similarMode)
                    <dialog class="app-venue-overlay app-venue-nearby-criteria-dialog"
                        data-venue-nearby-criteria-dialog aria-labelledby="app-venue-nearby-criteria-title">
                        <div class="app-venue-overlay__header">
                            <h2 id="app-venue-nearby-criteria-title">Критерии сходства</h2>
                            <button type="button" class="icon-button" data-venue-overlay-close aria-label="Закрыть">
                                <svg aria-hidden="true" viewBox="0 0 24 24">
                                    <path d="M18 6l-12 12"/><path d="M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <form method="get" action="{{ request()->url() }}#app-venue-nearby">
                            <input type="hidden" name="nearby" value="similar">
                            <input type="hidden" name="criteria[]" value="">
                            <div class="app-venue-nearby-criteria__content mskba-scroll">
                                <p class="muted">Отметь характеристики, которые должны совпадать у одной игровой зоны.</p>
                                @foreach($criteriaLabels as $key => $label)
                                    <label class="check-label">
                                        <input type="checkbox" name="criteria[]" value="{{ $key }}"
                                            @checked(in_array($key, $criteria, true))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="app-venue-overlay__actions">
                                <button class="button primary" type="submit">Применить</button>
                            </div>
                        </form>
                    </dialog>
                @endif
            @endif
        </div>
        <div class="app-venue-page__side-stack" @if(empty($venue->publicContacts)) data-venue-side-empty @endif>
            <aside class="app-venue-page__aside panel" aria-label="Быстрые действия">
                <strong>Хочешь здесь играть?</strong>
                <p>Посмотри ближайшие игры и присоединяйся к участникам или создай своё мероприятие.</p>
                <button type="button" class="button primary full" data-venue-play-open>Хочу здесь играть</button>
                <a class="button secondary full" href="#app-venue-schedule">{{ count($bookableCourtIds) ? 'Расписание и аренда' : 'Режим работы' }}</a>
            </aside>
            @if(!empty($venue->publicContacts))
                <section class="panel app-venue-block app-venue-public-contacts" aria-labelledby="venue-public-contacts-title">
                    <header class="app-venue-block__header"><h2 id="venue-public-contacts-title">Контакты площадки</h2></header>
                    @include('components.venue-public-contact-list', ['contacts' => $venue->publicContacts])
                </section>
            @endif
        </div>
    </div>
</section>
@include('theme::pages.events.partials.quick-create', ['quickEventVenueId' => $venue->id, 'quickEventCourtId' => $venue->isCourtPage ? $venue->selectedCourt['id'] : null])
@include('components.venue-play-funnel', ['venuePlayDirectEvents' => $plannedVenueEvents])
@include('theme::partials.venue-map-dialog', ['mapVenue' => $venue])
@php
    $rentalSearchCourts = collect($venue->courts)->whereIn('id', $bookableCourtIds)->when($venue->isCourtPage,
        fn ($courts) => $courts->where('id', $venue->selectedCourt['id']))->values()->map(
        fn ($court) => ['id' => $court['id'], 'name' => $court['name']])->all();
    $rentalSearchPolicy = $venue->isCourtPage
        ? ($venue->rental['effective'] ?? [])
        : (\App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)->where('active_marker', true)->first()?->only(
                ['minimum_lead_time_minutes', 'time_step_minutes']) ?? []);
@endphp
@include('theme::pages.venues.partials.rental-search-dialog')
<dialog class="app-venue-overlay app-venue-choice-dialog" data-venue-choice-dialog aria-labelledby="app-venue-choice-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-choice-title">{{ $venue->isCourtPage ? ($isOutdoorCourt ? 'Арендовать корт' : 'Арендовать зал') : 'Арендовать площадку' }}</h2>
        <button type="button" class="icon-button" data-venue-choice-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </div>
    <div class="app-venue-choice-dialog__body mskba-scroll">
        <p class="muted">Выбери зал или корт, затем дату и доступные временные интервалы.</p>
        <div class="field-group">
            <label for="app-venue-choice-court">Зал или корт</label>
            <span class="select-control">
                <select id="app-venue-choice-court" data-venue-choice-court required aria-describedby="app-venue-choice-error">
                    @if(count($rentalSearchCourts) > 1)
                        <option value="">Выберите зал или корт</option>
                    @endif
                    @foreach($rentalSearchCourts as $court)
                        <option value="{{ $court['id'] }}"
                            @selected(($venue->isCourtPage && $venue->selectedCourt['id'] === $court['id']) || count($rentalSearchCourts) === 1)>{{ $court['name'] }}</option>
                    @endforeach
                </select>
                <svg aria-hidden="true" viewBox="0 0 24 24"><use href="#chevron-down"/></svg>
            </span>
        </div>
        <p class="error" id="app-venue-choice-error" data-venue-choice-error role="alert" hidden>Выбери зал, чтобы посмотреть его расписание.</p>
        <button type="button" class="button primary" data-venue-choice-date>Выбрать дату</button>
    </div>
</dialog>
@include('components.venue-main-favorite-dialog')
<dialog class="app-venue-overlay app-venue-day-dialog" data-venue-day-dialog
    data-venue-name="{{ $venue->name }}"
    data-day-url="{{ route('venues.schedule.day', $venue->id) }}"
    data-court-id="{{ count($rentalSearchCourts) === 1 ? $rentalSearchCourts[0]['id'] : '' }}"
    data-court-name="{{ count($rentalSearchCourts) === 1 ? $rentalSearchCourts[0]['name'] : '' }}"
    aria-labelledby="app-venue-day-dialog-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-day-dialog-title" data-venue-day-title>{{ $venue->name }}</h2>
        <button type="button" class="icon-button" data-venue-overlay-close aria-label="Закрыть">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
        </button>
    </div>
    <div data-rental-result-header hidden>
        <div class="mskba-wizard__progress"><progress value="2" max="2" aria-label="Прогресс мастера"></progress><span>ШАГ 2 / 2</span></div>
        <p class="app-rental-search__note" data-rental-result-note role="status"></p>
        <div class="app-rental-search__tabs" data-rental-result-courts role="group" aria-label="Залы"></div>
    </div>
    @include('theme::pages.venues.partials.day-calendar')
    <p class="app-venue-day-dialog__status" role="status" aria-live="polite"
        data-venue-day-status hidden></p>
    <div class="app-venue-day-dialog__grid" data-venue-day-slots aria-label="Интервалы бронирования"></div>
    <form method="POST" action="{{ route('venues.rental.quote', $venue->id) }}"
        class="app-venue-day-dialog__footer" data-venue-day-booking-form
        data-pre-request-url="{{ route('venues.pre-requests.store', $venue->id) }}"
        data-pre-request-prepare-url="{{ route('venues.pre-requests.prepare', $venue->id) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="venue_court_id" data-venue-day-court>
        <input type="hidden" name="starts_at" data-venue-day-start>
        <input type="hidden" name="duration_minutes" data-venue-day-duration>
        <input type="hidden" name="scope" value="whole">
        <div class="app-venue-day-dialog__selection">
            <strong data-venue-day-summary>Выберите время</strong>
            <span data-venue-day-minimum></span>
        </div>
        <button type="button" class="button secondary" data-rental-result-back hidden>Назад</button>
        <button type="submit" class="button primary" data-venue-day-book disabled>
            Забронировать
        </button>
    </form>
</dialog>
@include('theme::pages.venues.partials.day-event-dialog')
@include('theme::pages.venues.partials.review-dialogs')
@auth
    @include('theme::pages.account.partials.direct-message-dialog')
@endauth
<dialog class="app-venue-overlay" data-venue-interests-dialog aria-label="Игроки, заинтересованные в площадке">
    <div class="app-venue-overlay__header"><h2>Площадкой интересуются</h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button></div>
    <div class="app-venue-interests-dialog__total" aria-label="{{ $activeVenueInterestCount }} {{ $venuePlayerWord }}">
        <strong class="app-venue-interests-dialog__total-number">{{ $activeVenueInterestCount }}</strong>
        <span class="app-venue-interests-dialog__total-word">{{ $venuePlayerWord }}</span>
    </div>
    @include('theme::pages.venues.partials.interest-users-list', ['venueAudience' => $venueAudience])
    @include('components.venue-favorite-follow', ['buttonClass' => 'button primary'])
</dialog>
<dialog class="app-venue-overlay app-venue-player-details-dialog" data-venue-player-details-dialog aria-labelledby="app-venue-player-details-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-player-details-title">Игровая информация</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </div>
    <div class="app-venue-player-details__body" data-venue-player-details-body></div>
</dialog>
<dialog class="app-venue-overlay app-venue-overlay--slot" data-venue-slot-dialog aria-labelledby="app-venue-slot-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-slot-title" data-venue-slot-title>Занятый слот</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
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
