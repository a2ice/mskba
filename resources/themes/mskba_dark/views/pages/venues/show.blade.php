@php
    if(isset($venue)) {
        $title = "{$venue->name}";
        $address = $venue->address;
        $displayAddress = $address?->display ?: $venue->rawAddress;
        $hasCoordinates = is_numeric($address?->latitude) && is_numeric($address?->longitude);
        $hasMap = $hasCoordinates && $venue->about->mapApiKey;
        $yandexMapUrl = $displayAddress
            ? 'https://yandex.ru/maps/?text=' . urlencode($displayAddress)
            : null;
        $shortWeekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $shortMonths = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн', 7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];
        $placeholderDateLabel = static fn ($date) => $date->format('d') . ' ' . $shortMonths[(int) $date->month];
        $placeholderWeekdayLabel = static fn ($date) => $shortWeekdays[(int) $date->dayOfWeekIso];
        $legacyBookableCourtIds = collect($venue->courtSchedules)->where('bookingEnabled', true)->pluck('id')->all();
        // On a multi-zone venue the aggregate occupancy calendar would mix
        // independent areas. Show that calendar only on an individual court,
        // or on a venue with exactly one court.
        $legacyShowOccupancy = $legacyBookableCourtIds !== []
            && ($venue->isCourtPage || count($venue->courtSchedules) === 1);
        $legacyCreateEventUrl = route('events.wizard', array_filter([
            'venue_id' => $venue->id,
            'venue_court_id' => $venue->isCourtPage ? $venue->selectedCourt['id'] : null,
        ]));
        $legacyAudience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue(\App\Modules\Venue\Domain\Models\Venue::query()->findOrFail($venue->id));
        $legacyInterestCount = (int) $legacyAudience['total'];
        $legacyInterestUsers = collect(array_merge($legacyAudience['direct'], $legacyAudience['matched']))
            ->sortByDesc(fn (array $player): bool => filled($player['avatar']))->values();
        $legacyCriteriaLabels = [
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
        $legacyNearbySimilar = request('nearby') === 'similar';
        $legacyCriteria = array_values(array_intersect(array_keys($legacyCriteriaLabels),
            (array) request('criteria', ['type', 'environment', 'payment'])));
        $legacyNearby = [];
        $legacyNearbyCards = collect();
        if ($hasCoordinates) {
            $nearbyHandler = app(\App\Modules\Venue\Application\UseCases\FindNearbyVenuesHandler::class);
            $latitude = (float) $address->latitude;
            $longitude = (float) $address->longitude;
            $legacyNearby = $legacyNearbySimilar
                ? $nearbyHandler->similar($venue->id, $latitude, $longitude,
                    $venue->isCourtPage ? (int) $venue->selectedCourt['id'] : null, $legacyCriteria)
                : $nearbyHandler->handle($venue->id, $latitude, $longitude, 6, 10);
            if (! $legacyNearbySimilar && $legacyNearby === []) {
                $legacyNearby = $nearbyHandler->handle($venue->id, $latitude, $longitude, 3);
            }
            $legacyNearbyCards = collect(app(\App\Modules\Venue\Application\UseCases\ListVenuesHandler::class)
                ->handle(null, null, [], null, array_column($legacyNearby, 'id')))->keyBy('id');
        }
        $favoritesCount = \App\Modules\Favorites\Domain\Models\Favorite::query()
            ->where('entity_type', 'venue')->where('entity_id', $venue->id)
            ->whereNull('trashed_at')->count();
        $ratingPercent = max(0, min(100, (($venue->about->rating ?? 0) / 5) * 100));
        $ratingLabel = $venue->about->rating
            ? 'Рейтинг: ' . number_format($venue->about->rating, 1, ',', ' ') . ' из 5'
            : 'Рейтинг пока не сформирован';
        $headingTitleIsTruncated = mb_strlen($venue->name) > 30;
        $headingTitle = $headingTitleIsTruncated
            ? mb_substr($venue->name, 0, 30) . '…'
            : $venue->name;
    } else {
        $title = 'Ошибка';
        $error_message = isset($error['message']) ? $error['message'] : 'Неизвестная ошибка';
        $title .= " - $error_message";
    }
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venue',
    'sectionClass' => 'venue-section',
    'contentTitle' => isset($venue) ? $headingTitle : 'Площадка',
    'entityEditUrl' => isset($venue) && $venue->canEdit ? route('account.venues.edit', $venue->routeIdentifier()) : null,
    'contentTitleTooltip' => isset($venue) && $headingTitleIsTruncated ? $venue->name : null,
    'contentSubtitle' => isset($venue) ? $venue->shortDescription : null,
    'sidebarLabel' => 'Навигация площадки',
])

@section('section-sidebar')
    @if(!empty($venue))
        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Площадка</h2>
            <nav class="venue-side-nav" aria-label="Разделы площадки" data-venue-mobile-nav>
                @foreach($venue->sections as $section)
                    <a href="#{{ $section['id'] }}" @class(['venue-side-nav__link', 'is-muted' => ! $section['isAvailable']]) data-venue-anchor-link>
                        <span>{{ $section['label'] }}</span>
                        @if(! $section['isAvailable'])
                            <span class="venue-side-nav__badge">скоро</span>
                        @endif
                    </a>
                @endforeach
                @if($hasCoordinates)
                    <a href="#venue-nearby" class="venue-side-nav__link" data-venue-anchor-link>
                        <span>Площадки рядом</span>
                    </a>
                @endif
            </nav>
        </div>

        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Состояние</h2>
            <dl class="venue-side-meta">
                <div>
                    <dt>Тип</dt>
                    <dd>{{ $venue->type }}</dd>
                </div>
                <div>
                    <dt>Статус</dt>
                    <dd>{{ $venue->status }}</dd>
                </div>
                <div>
                    <dt>Рейтинг</dt>
                    <dd>{{ $venue->about->rating ? number_format($venue->about->rating, 1, ',', ' ') : 'Готовится' }}</dd>
                </div>
            </dl>
        </div>

        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Управление</h2>
            @if($venue->canEdit || $venue->canEditSchedule)
                <div class="venue-management-actions">
                    @if($venue->canEdit)
                        <a href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Редактировать</a>
                        <a href="{{ route('account.venues.status', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Модерация</a>
                    @endif
                    @if($venue->canEditSchedule)
                        <a href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Расписание</a>
                    @endif
                </div>
            @else
                <p class="section-sidebar-block__text">
                    Доступные действия появятся здесь, если у пользователя есть права на управление площадкой.
                </p>
            @endif
            @if(auth()->user()?->canonical()->isConfirmed() && app(\App\Support\Features\FeatureFlags::class)->enabled(\App\Support\Features\VenueRentalFeature::RENTAL_FLOW))
                <div class="venue-management-actions mt-3">
                    <a href="{{ route('venues.ownership-claims.create', $venue->id) }}" class="btn btn--secondary btn--sm">Подтвердить владение</a>
                    @if(isset($commercialMembershipsUrl))
                        <a href="{{ $commercialMembershipsUrl }}" class="btn btn--secondary btn--sm">Коммерческие роли</a>
                    @endif
                    @if(isset($groupOffersManagementUrl))
                        <a href="{{ $groupOffersManagementUrl }}" class="btn btn--secondary btn--sm">Промокоды</a>
                    @endif
                    @if(isset($bookingPolicyUrl))
                        <a href="{{ $bookingPolicyUrl }}" class="btn btn--secondary btn--sm">Условия аренды</a>
                    @endif
                </div>
            @endif
        </div>
    @else
        <div class="section-sidebar-block">
            @include('theme::partials.menu.sidebar', ['page' => 'venues', 'sidebarTitle' => 'Площадки'])
        </div>
    @endif
    @if(isset($venue))
        @include('theme::pages.events.partials.quick-create', ['quickEventVenueId' => $venue->id, 'quickEventCourtId' => $venue->isCourtPage ? $venue->selectedCourt['id'] : null])
        @include('components.venue-play-funnel')
        @include('components.venue-main-favorite-dialog')
    @endif
@endsection

@section('section-mobile-sticky-navigation')
    @if(!empty($venue))
        <nav class="venue-mobile-sticky-nav" aria-label="Разделы площадки" data-venue-mobile-nav>
            @foreach($venue->sections as $section)
                <a href="#{{ $section['id'] }}" @class(['venue-side-nav__link', 'is-muted' => ! $section['isAvailable']]) data-venue-anchor-link>
                    <span>{{ $section['label'] }}</span>
                    @if(! $section['isAvailable'])
                        <span class="venue-side-nav__badge">скоро</span>
                    @endif
                </a>
            @endforeach
        </nav>
    @endif
@endsection

@section('section-heading-leading')
    @if(!empty($venue))
        @php
            $headingStatus = match ($venue->statusSlug) {
                'confirmed' => ['modifier' => 'confirmed', 'icon' => 'ti-check'],
                'blocked' => ['modifier' => 'blocked', 'icon' => 'ti-lock'],
                default => ['modifier' => 'unconfirmed', 'icon' => 'ti-clock'],
            };
        @endphp
        <span
            class="venue-heading-status venue-heading-status--{{ $headingStatus['modifier'] }}"
            role="img"
            aria-label="Статус площадки: {{ $venue->status }}"
            title="{{ $venue->status }}"
            data-tooltip-variant="title"
        >
            <i class="ti {{ $headingStatus['icon'] }}" aria-hidden="true"></i>
        </span>
    @endif
@endsection

@section('section-heading-action')
    @if(!empty($venue))
        <div class="venue-heading-actions">
            <div
                class="venue-star-rating"
                role="img"
                aria-label="{{ $ratingLabel }}"
                title="{{ $ratingLabel }}"
                data-tooltip-variant="title"
            >
                <span class="venue-star-rating__base" aria-hidden="true">★★★★★</span>
                <span class="venue-star-rating__fill" style="width: {{ $ratingPercent }}%" aria-hidden="true">★★★★★</span>
                <span class="venue-star-rating__compact" aria-hidden="true">
                    <span class="venue-star-rating__compact-star">★</span>
                    <span>{{ number_format($venue->about->rating ?? 0, 1, '.', ' ') }}</span>
                </span>
            </div>
            @if($legacyBookableCourtIds !== [])
                <a href="#schedule" class="btn btn--primary btn--sm venue-booking-action">
                    <i class="ti ti-player-play venue-booking-action__icon" aria-hidden="true"></i>
                    <span class="venue-booking-action__label">{{ $venue->isCourtPage
                        ? ($venue->selectedCourt['environment'] === 'outdoor' ? 'Арендовать корт' : 'Арендовать зал')
                        : 'Арендовать площадку' }}</span>
                </a>
            @endif
        </div>
    @endif
@endsection

@section('section-content')
@if(isset($venue))
    @php
    $bannerVenue = \App\Modules\Venue\Domain\Models\Venue::query()->find($venue->id);
@endphp
    @include('components.entity-public-banner', [
        'bannerUrl' => $bannerVenue?->bannerUrl(),
        'bannerMode' => $bannerVenue?->banner_display_mode,
        'overlayCss' => $bannerVenue?->bannerOverlayCss(),
    ])
@endif
    @if(!empty($venue))
        <div class="venue-show">
            <nav class="venue-anchor-nav" aria-label="Быстрая навигация" data-venue-anchor-nav>
                @foreach($venue->sections as $section)
                    <a href="#{{ $section['id'] }}" @class(['venue-anchor-nav__link', 'is-muted' => ! $section['isAvailable']]) data-venue-anchor-link>
                        {{ $section['label'] }}
                    </a>
                @endforeach
            </nav>

            <section class="venue-hero" aria-label="Краткая информация">
                <div class="venue-hero__media" data-venue-hero-slider>
                    @if($venue->featuredMedia !== [])
                        <div class="venue-hero-slider__track">
                            @foreach($venue->featuredMedia as $index => $media)
                                <button
                                    type="button"
                                    @class(['venue-hero-slider__slide', 'is-active' => $index === 0])
                                    aria-label="Открыть фото {{ $index + 1 }} из {{ count($venue->featuredMedia) }}"
                                    aria-hidden="{{ $index === 0 ? 'false' : 'true' }}"
                                    @if($index !== 0) tabindex="-1" @endif
                                    data-venue-hero-slide
                                    data-venue-gallery-item
                                    data-index="{{ $index }}"
                                    data-url="{{ $media['url'] }}"
                                    data-title="{{ $media['title'] ?: $venue->name }}"
                                    data-description="{{ $media['description'] ?: '' }}"
                                >
                                    <img
                                        src="{{ $media['url'] }}"
                                        alt="{{ $media['title'] ?: $venue->name }}"
                                        @if($index > 0) loading="lazy" @endif
                                        data-venue-hero-image
                                    >
                                </button>
                            @endforeach
                        </div>

                        @if(count($venue->featuredMedia) > 1)
                            <button type="button" class="venue-hero-slider__nav venue-hero-slider__nav--prev" data-venue-hero-prev aria-label="Предыдущее фото">
                                <i class="ti ti-chevron-left" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="venue-hero-slider__nav venue-hero-slider__nav--next" data-venue-hero-next aria-label="Следующее фото">
                                <i class="ti ti-chevron-right" aria-hidden="true"></i>
                            </button>
                        @endif
                    @else
                        <div class="venue-hero__placeholder">
                            <img src="{{ asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $venue->name }}" data-venue-hero-image>
                        </div>
                    @endif
                    <div class="venue-hero__media-status">
                        <span class="venue-pill">{{ $venue->type }}</span>
                        <div @class(['venue-opening-state', 'is-open' => $venue->isOpen, 'is-closed' => ! $venue->isOpen])>
                            <span class="venue-opening-state__dot" aria-hidden="true"></span>
                            <strong>{{ $venue->isOpen ? 'Открыта' : 'Закрыта' }}</strong>
                        </div>
                    </div>
                </div>

                <div class="venue-hero__summary">
                    <div class="venue-play-hero-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px">
                        <button type="button" class="btn btn--primary" data-venue-play-open>Хочу здесь играть</button>
                        <button type="button" class="btn btn--secondary" data-venue-favorite-main data-favorite-count="{{ $favoritesCount }}" aria-pressed="false"><svg class="venue-favorite-follow__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg><span data-venue-favorite-main-label>В избранное@if($favoritesCount > 0) ({{ $favoritesCount }})@endif</span></button>
                    </div>
                    <div class="venue-hero__details">
                        <div>
                            <span class="venue-hero__detail-label">Часы работы</span>
                            <p class="venue-hero__text">
                                {{ $venue->todayHours }}
                                @if($venue->about->scheduleDays !== [])
                                    <br><a href="#schedule" class="venue-hero__map-link fc-link">Смотреть расписание</a>
                                @endif
                            </p>
                        </div>
                        @if($legacyShowOccupancy)
                        <div>
                            <span class="venue-hero__detail-label">Занятые слоты</span>
                            <div class="venue-occupancy-calendar" aria-label="Занятость площадки на девять дней">
                                @foreach($venue->occupancyDays as $dayIndex => $day)
                                    @php
                                        $slotCount = count($day['slots']);
                                        $dayStateLabel = match ($day['state']) {
                                            'confirmed' => 'есть подтверждённые бронирования',
                                            'tentative' => 'есть неподтверждённые бронирования',
                                            default => 'свободно',
                                        };
                                    @endphp
                                    <button
                                        type="button"
                                        @class([
                                            'venue-occupancy-calendar__day',
                                            'is-today' => $day['isToday'],
                                            'is-confirmed' => $day['state'] === 'confirmed',
                                            'is-tentative' => $day['state'] === 'tentative',
                                            'is-free' => $day['state'] === 'free',
                                        ])
                                        aria-label="{{ $day['label'] }}, {{ $day['weekday'] }}: {{ $dayStateLabel }}"
                                        data-venue-occupancy-day
                                        data-day-index="{{ $dayIndex }}"
                                        data-day-date="{{ $day['date'] }}"
                                        data-day-label="{{ $day['label'] }}"
                                        data-day-weekday="{{ $day['weekday'] }}"
                                        data-is-today="{{ $day['isToday'] ? '1' : '0' }}"
                                        data-handler="modal"
                                        data-modal-action="open"
                                        data-modal-target="venue-occupancy-schedule"
                                    >
                                        <span>{{ $day['weekday'] }}</span>
                                        <strong>{{ $day['label'] }}</strong>
                                        <small>{{ $slotCount > 0 ? $slotCount : '—' }}</small>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </section>

            @if($legacyShowOccupancy)
            @component('theme::partials.modal.layout', [
                'id' => 'venue-occupancy-schedule',
                'dialogClass' => 'venue-occupancy-modal__dialog',
            ])
                <div
                    class="venue-occupancy-modal"
                    data-venue-occupancy-modal
                    @if($venue->rental) data-venue-rental='@json($venue->rental)' @endif
                    data-booking-intent-date="{{ request()->query('booking_date', '') }}"
                    data-booking-intent-start="{{ request()->query('booking_start', '') }}"
                >
                    <p class="venue-occupancy-modal__eyebrow">Расписание площадки</p>
                    <h2 class="modal_title" id="modal-title-venue-occupancy-schedule" data-venue-occupancy-title>Занятые слоты</h2>
                    <p class="venue-occupancy-modal__date" data-venue-occupancy-date></p>

                    <div class="venue-occupancy-modal__days">
                        @foreach($venue->occupancyDays as $dayIndex => $day)
                            <section data-venue-occupancy-panel data-day-index="{{ $dayIndex }}" data-day-date="{{ $day['date'] }}" @if($dayIndex !== 0) hidden @endif>
                                <div class="venue-rental-timeline" data-venue-rental-timeline>
                                @forelse($day['timeline'] as $timelineItem)
                                    @if($timelineItem['kind'] === 'occupied')
                                        @php
                                            $timelineAriaLabel = collect($timelineItem['slots'])
                                                ->map(fn ($slot) => $slot['timeLabel'].' · '.$slot['eventTypeLabel'].' · '.$slot['statusIcon'].' '.$slot['statusLabel'])
                                                ->join('; ');
                                        @endphp
                                        <article class="venue-occupancy-slot is-occupied" aria-label="{{ $timelineAriaLabel }}" data-venue-occupied-slot>
                                            <time>{{ $timelineItem['startsAt'] === '24:00' ? '00:00' : $timelineItem['startsAt'] }}–{{ in_array($timelineItem['endsAt'], ['23:59', '24:00'], true) ? '00:00' : $timelineItem['endsAt'] }}</time>
                                            <div>
                                                @foreach($timelineItem['slots'] as $slot)
                                                    <span class="venue-occupancy-slot__booking">
                                                        @if($slot['eventUrl'])
                                                            <a class="fc-link venue-occupancy-slot__event" href="{{ $slot['eventUrl'] }}" target="_blank" rel="noopener">{{ $slot['eventTypeLabel'] }}</a>
                                                        @else
                                                            <strong>{{ $slot['eventTypeLabel'] }}</strong>
                                                        @endif
                                                        <span class="venue-occupancy-slot__status venue-occupancy-slot__status--{{ $slot['status'] }}">
                                                            <span aria-hidden="true"></span>{{ $slot['statusLabel'] }}
                                                            @if($slot['bookingId'])
                                                                <button type="button" class="fc-link" data-venue-booking-details data-booking-id="{{ $slot['bookingId'] }}" data-booking-status-url="{{ $slot['statusUrl'] }}" data-booking-details-url="{{ $slot['bookingUrl'] }}">посмотреть</button>
                                                            @endif
                                                        </span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        </article>
                                    @else
                                        <article @class(['venue-rental-cell', 'is-disabled' => ! $timelineItem['bookable']]) data-venue-rental-cell data-start="{{ $timelineItem['startsAt'] }}" data-end="{{ $timelineItem['endsAt'] }}" data-available-minutes="{{ $timelineItem['availableMinutes'] }}">
                                            <button type="button" data-venue-rental-cell-open aria-expanded="false" @disabled(! $timelineItem['bookable'] || ! $venue->rental)>
                                                <time>{{ $timelineItem['startsAt'] === '24:00' ? '00:00' : $timelineItem['startsAt'] }}–{{ in_array($timelineItem['endsAt'], ['23:59', '24:00'], true) ? '00:00' : $timelineItem['endsAt'] }}</time>
                                                <span>{{ $timelineItem['bookable'] && $venue->rental ? 'Забронировать' : 'Недоступно' }}</span>
                                            </button>
                                        </article>
                                    @endif
                                @empty
                                    <div class="venue-occupancy-modal__empty">
                                        <i class="ti ti-calendar-check" aria-hidden="true"></i>
                                        <strong>Расписание не задано</strong>
                                        <span>Свяжитесь с площадкой, чтобы уточнить доступное время.</span>
                                    </div>
                                @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <footer class="venue-occupancy-modal__footer" data-modal-footer>
                        <button type="button" class="btn btn--secondary" data-venue-occupancy-prev disabled>
                            <i class="ti ti-arrow-left" aria-hidden="true"></i> Пред. день
                        </button>
                        <button type="button" class="btn btn--primary" data-venue-occupancy-next>
                            След. день <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </button>
                    </footer>
                </div>
            @endcomponent
            @endif
            <button type="button" class="js-handler" hidden data-venue-booking-details-trigger data-handler="modal" data-modal-action="open" data-modal-target="venue-booking-details"></button>

            @component('theme::partials.modal.layout', [
                'id' => 'venue-booking-details',
                'dialogClass' => 'venue-booking-details-modal__dialog',
            ])
                <div class="venue-booking-details-modal" data-venue-booking-details-modal>
                    <p class="venue-occupancy-modal__eyebrow">Заявка на аренду</p>
                    <h2 class="modal_title" id="modal-title-venue-booking-details">Параметры заявки</h2>
                    <div class="venue-booking-details-modal__content" data-venue-booking-details-content></div>
                    <p class="venue-booking-details-modal__message" data-venue-booking-details-message aria-live="polite"></p>
                    <footer class="venue-booking-details-modal__footer" data-modal-footer>
                        <a class="btn btn--secondary" href="#" data-venue-booking-details-page>Открыть заявку</a>
                        <button type="button" class="btn btn--primary" data-venue-booking-refresh>Обновить статус</button>
                    </footer>
                </div>
            @endcomponent

            @guest
                <button type="button" class="js-handler" hidden data-venue-booking-auth data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic"></button>
            @endguest

            @if($venue->featuredMedia !== [])
                <div class="venue-gallery-modal" data-venue-gallery-modal hidden>
                    <div class="venue-gallery-modal__backdrop" data-venue-gallery-close></div>
                    <section class="venue-gallery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-gallery-modal-title">
                        <button type="button" class="venue-gallery-modal__close" data-venue-gallery-close aria-label="Закрыть">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </button>

                        <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--prev" data-venue-gallery-prev aria-label="Предыдущее фото">
                            <i class="ti ti-chevron-left" aria-hidden="true"></i>
                        </button>

                        <img src="" alt="" data-venue-gallery-image>

                        <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--next" data-venue-gallery-next aria-label="Следующее фото">
                            <i class="ti ti-chevron-right" aria-hidden="true"></i>
                        </button>

                        <div class="venue-gallery-modal__caption">
                            <h3 id="venue-gallery-modal-title" data-venue-gallery-title></h3>
                            <p data-venue-gallery-description></p>
                        </div>
                    </section>
                </div>
            @endif

            <section id="address" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <h2>Адрес</h2>
                </div>

                <div class="venue-address-grid">
                    <div class="venue-map-frame" data-venue-map-frame>
                        @if($hasMap)
                            <div
                                class="venue-map"
                                data-venue-map
                                data-yandex-map-api-key="{{ $venue->about->mapApiKey }}"
                                data-latitude="{{ $address->latitude }}"
                                data-longitude="{{ $address->longitude }}"
                                data-title="{{ $venue->name }}"
                                data-address="{{ $displayAddress }}"
                                aria-label="Карта площадки {{ $venue->name }}"
                            ></div>
                            <script type="application/json" data-venue-nearby-points>@json($nearbyVenues ?? [])</script>
                        @endif

                        <div class="venue-map-placeholder" data-venue-map-fallback @if($hasMap) hidden @endif>
                            <div class="venue-map-placeholder__marker" aria-hidden="true"></div>
                            <div>
                                <p class="venue-map-placeholder__title">Карта площадки</p>
                                <p class="venue-map-placeholder__text" data-venue-map-fallback-message>
                                    @if(! $venue->about->mapApiKey)
                                        Ключ Яндекс Карт не настроен.
                                    @elseif($hasCoordinates)
                                        Координаты сохранены: {{ $address->latitude }}, {{ $address->longitude }}.
                                    @else
                                        Координаты площадки пока не указаны.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="venue-address-card">
                        <dl class="venue-address-list">
                            <div>
                                <dt>Адрес</dt>
                                <dd>
                                    @if($address?->postalCode){{ $address->postalCode }}, @endif{{ $displayAddress ?: 'адрес пока не указан' }}
                                </dd>
                            </div>
                        </dl>

                        <div class="venue-metro-list">
                            <p class="venue-metro-list__title">Метро</p>
                            @forelse($venue->metroStations as $station)
                                @php
                                    $lineColor = $station->lineColor && preg_match('/^#[0-9a-fA-F]{3,8}$/', $station->lineColor)
                                        ? $station->lineColor
                                        : '#ec7f12';
                                @endphp
                                <div class="venue-metro">
                                    <span
                                        class="venue-metro__bullet"
                                        style="background-color: {{ $lineColor }}"
                                        @if($station->lineName)
                                            role="img"
                                            aria-label="Линия метро: {{ $station->lineName }}"
                                            title="{{ $station->lineName }}"
                                            data-tooltip-variant="title"
                                            data-tooltip-icon
                                        @else
                                            aria-hidden="true"
                                        @endif
                                    ></span>
                                    <span class="venue-metro__name">{{ $station->name }}</span>
                                </div>
                            @empty
                                <p class="venue-placeholder-text">Станции метро пока не привязаны к локации.</p>
                            @endforelse
                        </div>

                        @if($yandexMapUrl)
                            <div class="venue-address-card__actions">
                                <a href="{{ $yandexMapUrl }}" class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                                    Маршрут
                                </a>
                            </div>
                        @endif

                        <button
                            type="button"
                            class="venue-address-card__nearby"
                            data-venue-nearby-open
                            aria-pressed="false"
                            @disabled(! $hasMap || empty($nearbyVenues))
                        >
                            Площадки рядом
                        </button>
                    </div>
                </div>
            </section>

            <section id="activities" class="venue-show-section venue-activities" data-venue-activities>
                <div class="venue-show-section__heading venue-activities__heading">
                    <div>
                        <span class="venue-activities__eyebrow">На этой площадке</span>
                        <h2>Игры и мероприятия</h2>
                        <a class="btn btn--secondary btn--sm" href="{{ route('events.wizard', ['venue_id' => $venue->id]) }}"
                            data-quick-event-open data-quick-event-venue-id="{{ $venue->id }}">Создать мероприятие</a>
                    </div>
                    <span class="venue-section-state" data-venue-activities-state>Загрузка…</span>
                </div>
                <div class="venue-activities__body" data-venue-activities-body>
                    <div class="venue-activities__loading">Загружаем текущие и ближайшие активности…</div>
                </div>

                <div class="venue-day-modal" data-venue-information-modal hidden>
                    <div class="venue-day-modal__backdrop" data-venue-information-close></div>
                    <section
                        class="venue-day-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="venue-information-modal-title"
                    >
                        <div class="venue-day-modal__head">
                            <div>
                                <p class="venue-day-modal__eyebrow">Информация о площадке</p>
                                <h3 id="venue-information-modal-title">Уточнить информацию</h3>
                            </div>
                            <button type="button" class="venue-day-modal__close" data-venue-information-close aria-label="Закрыть">
                                <i class="ti ti-x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="venue-day-modal__body">
                            <div class="venue-day-modal__notice">
                                Возможность отправить уточнение владельцу площадки появится в одном из следующих обновлений.
                            </div>
                        </div>
                    </section>
                </div>
            </section>

            <section id="amenities" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <h2>Опции</h2>
                    <span class="venue-section-state">
                        {{ $venue->amenities === [] ? 'заглушка' : count($venue->amenities) . ' опций' }}
                    </span>
                </div>

                @if($venue->amenities !== [])
                    <div class="venue-amenities">
                        @foreach($venue->amenities as $amenity)
                            <article class="venue-amenity">
                                <div class="venue-amenity__icon" aria-hidden="true">
                                    @if($amenity->icon)
                                        <i class="ti {{ $amenity->icon }}"></i>
                                    @else
                                        <i class="ti ti-check"></i>
                                    @endif
                                </div>
                                <div class="venue-amenity__content">
                                    <h3>{{ $amenity->name }}</h3>
                                    @if($amenity->note || $amenity->description)
                                        <p>{{ $amenity->note ?: $amenity->description }}</p>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="venue-empty-block">
                        <p>Здесь будут особенности площадки: покрытие, раздевалки, душевые, инвентарь, парковка и другие параметры.</p>
                    </div>
                @endif
            </section>

            @if($legacyInterestCount > 0)
            <section id="venue-interest" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <h2>Площадкой интересуются</h2>
                </div>
                    <div class="venue-nearby__interest-avatars" aria-label="Заинтересованные игроки">
                        @foreach($legacyInterestUsers->take(5) as $player)
                            <span class="venue-nearby__avatar" title="{{ $player['public'] || $player['is_self'] ? $player['name'] : 'Игрок' }}">
                                @if(($player['public'] || $player['is_self']) && $player['avatar'])
                                    <img src="{{ $player['avatar'] }}" alt="{{ $player['name'] }}" loading="lazy">
                                @elseif($player['public'] || $player['is_self'])
                                    {{ mb_strtoupper(mb_substr($player['name'], 0, 1)) }}
                                @else
                                    <i class="ti ti-user" aria-hidden="true"></i>
                                @endif
                            </span>
                        @endforeach
                    </div>
                    <p>{{ $legacyInterestCount }} {{ $legacyInterestCount % 100 >= 11 && $legacyInterestCount % 100 <= 14
                        ? 'игроков' : (match ($legacyInterestCount % 10) {1 => 'игрок', 2, 3, 4 => 'игрока', default => 'игроков'}) }}</p>
                    <button type="button" class="btn btn--secondary btn--sm" data-venue-interest-count-open>Посмотреть всех</button>
                    <dialog class="venue-nearby__dialog" data-venue-interest-count-dialog aria-labelledby="venue-interest-count-title">
                        <header class="venue-nearby__dialog-header">
                            <h2 id="venue-interest-count-title">Площадкой интересуются</h2>
                            <button type="button" class="btn btn--secondary btn--sm" data-venue-nearby-close aria-label="Закрыть"><i class="ti ti-x"></i></button>
                        </header>
                        <p>{{ $legacyInterestCount }} игроков</p>
                        <div class="venue-nearby__interest-list">
                            @foreach($legacyInterestUsers as $player)
                                <p>{{ $player['public'] || $player['is_self'] ? $player['name'] : 'Игрок' }}</p>
                            @endforeach
                        </div>
                    </dialog>
            </section>
            @endif
            <section id="schedule" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <div>
                        <h2>Расписание</h2>
                        <p class="venue-show-section__caption">{{ $venue->isCourtPage && $legacyBookableCourtIds !== []
                            ? 'Нажмите на день, чтобы посмотреть интервалы.'
                            : 'Режим работы залов и кортов с учётом исключений.' }}</p>
                    </div>
                    <div class="venue-management-actions">
                        @if(isset($rentalUrl) && $legacyBookableCourtIds !== [])
                            <a href="{{ $rentalUrl }}" class="btn btn--primary btn--sm">Рассчитать аренду</a>
                        @endif
                        <span class="venue-section-state">{{ $legacyShowOccupancy ? '14 дней' : 'Недельный график' }}</span>
                    </div>
                </div>

                @if(! $venue->isCourtPage || $legacyBookableCourtIds === [])
                    @forelse($venue->courtSchedules as $scheduleGroup)
                        <div class="venue-show-section__court">
                            @unless($venue->isCourtPage)
                                <h3>{{ $scheduleGroup['name'] }}</h3>
                            @endunless
                            @if($scheduleGroup['bookingEnabled'])
                                <p>Онлайн-бронирование доступно.</p>
                                <a href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $scheduleGroup['routeIdentifier']]) }}" class="btn btn--secondary btn--sm">Расписание и аренда</a>
                            @else
                                <p>Онлайн-бронирование через портал недоступно.</p>
                                @include('venues.partials.informational-hours', ['scheduleGroup' => $scheduleGroup, 'legacyHours' => true])
                            @endif
                        </div>
                    @empty
                        <p>Режим работы не указан.</p>
                    @endforelse
                @elseif($venue->about->scheduleDays !== [])
                    <div class="venue-schedule-preview">
                        @foreach($venue->about->scheduleDays as $day)
                            <button
                                type="button"
                                @class([
                                'venue-schedule-preview__day',
                                'is-today' => $day['isToday'],
                                'is-closed' => $day['isClosed'],
                                ])
                                data-venue-day-card
                                data-date="{{ $day['date'] }}"
                                data-label="{{ $day['label'] }}"
                                data-weekday="{{ $day['weekday'] }}"
                                data-is-today="{{ $day['isToday'] ? '1' : '0' }}"
                                data-is-closed="{{ $day['isClosed'] ? '1' : '0' }}"
                                data-intervals='@json($day['intervals'])'
                            >
                                <div>
                                    <span>{{ $day['label'] }}</span>
                                    <small>{{ $day['weekday'] }}</small>
                                </div>

                                @if($day['isClosed'])
                                    <strong>Закрыто</strong>
                                @else
                                    <div class="venue-schedule-preview__intervals">
                                        @foreach($day['intervals'] as $interval)
                                            <strong>{{ $interval['startsAt'] === '24:00' ? '00:00' : $interval['startsAt'] }}-{{ in_array($interval['endsAt'], ['23:59', '24:00'], true) ? '00:00' : $interval['endsAt'] }}</strong>
                                        @endforeach
                                    </div>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <div class="venue-schedule-legend" aria-label="Легенда расписания">
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--open"></i>Есть интервалы</span>
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--closed"></i>Закрыто</span>
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--today"></i>Сегодня</span>
                    </div>

                    <div class="venue-day-modal" data-venue-day-modal hidden>
                        <div class="venue-day-modal__backdrop" data-venue-day-modal-close></div>
                        <section class="venue-day-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-day-modal-title">
                            <div class="venue-day-modal__head">
                                <div>
                                    <p class="venue-day-modal__eyebrow" data-venue-day-modal-weekday></p>
                                    <h3 id="venue-day-modal-title" data-venue-day-modal-title>День расписания</h3>
                                </div>
                                <button type="button" class="venue-day-modal__close" data-venue-day-modal-close aria-label="Закрыть">
                                    <i class="ti ti-x"></i>
                                </button>
                            </div>

                            <div class="venue-day-modal__body">
                                <div data-venue-day-modal-intervals></div>
                                <div class="venue-day-modal__notice">
                                    Бронирование появится здесь после подключения модуля событий и заявок.
                                </div>
                            </div>
                        </section>
                    </div>
                @else
                    <div class="venue-schedule-preview">
                        @for($day = 0; $day < 7; $day++)
                            @php($placeholderDate = now()->addDays($day))
                            <div class="venue-schedule-preview__day">
                                <div>
                                    <span>{{ $placeholderDateLabel($placeholderDate) }}</span>
                                    <small>{{ $placeholderWeekdayLabel($placeholderDate) }}</small>
                                </div>
                                <strong>Режим работы не указан</strong>
                            </div>
                        @endfor
                    </div>
                @endif
            </section>
            <section id="posts" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <h2>Посты</h2>
                    <span class="venue-section-state">заглушка</span>
                </div>

                <div class="venue-empty-block">
                    <p>Лента площадки появится после подключения публикаций и новостей площадки.</p>
                </div>
            </section>

            <section id="reviews" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <h2>Отзывы</h2>
                    <span class="venue-section-state">
                        {{ $venue->about->ratingCount ? $venue->about->ratingCount . ' оценок' : 'заглушка' }}
                    </span>
                </div>

                @if($venue->reviews !== [])
                    <div class="venue-reviews">
                        @foreach($venue->reviews as $review)
                            <article class="venue-review">
                                <div class="venue-review__head">
                                    <div>
                                        <h3>{{ $review->authorName }}</h3>
                                        @if($review->publishedAt)
                                            <span>{{ $review->publishedAt }}</span>
                                        @endif
                                    </div>
                                    <strong>{{ number_format($review->rating, 1, ',', ' ') }}</strong>
                                </div>

                                @if($review->body)
                                    <p>{{ $review->body }}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="venue-empty-block">
                        <p>Отзывы и рейтинг появятся после публикации первых оценок площадки.</p>
                    </div>
                @endif
            </section>

            @if($hasCoordinates)
                <section id="venue-nearby" class="venue-show-section">
                    <div class="venue-show-section__heading"><h2>Площадки рядом</h2></div>
                    <div class="venue-nearby__toolbar" aria-label="Режим поиска площадок">
                        <a class="btn {{ ! $legacyNearbySimilar ? 'btn--primary' : 'btn--secondary' }}"
                            href="{{ request()->fullUrlWithQuery(['nearby' => 'all']) }}#venue-nearby"
                            @if(! $legacyNearbySimilar) aria-current="true" @endif>Все</a>
                        <a class="btn {{ $legacyNearbySimilar ? 'btn--primary' : 'btn--secondary' }}"
                            href="{{ request()->fullUrlWithQuery(['nearby' => 'similar']) }}#venue-nearby"
                            @if($legacyNearbySimilar) aria-current="true" @endif>Похожие</a>
                        @if($legacyNearbySimilar)
                            <button type="button" class="btn btn--secondary" data-venue-nearby-criteria-open>
                                <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>Критерии сходства
                            </button>
                        @endif
                    </div>
                    @if($legacyNearby !== [])
                        <div class="venue-nearby__cards">
                            @foreach($legacyNearby as $item)
                                @if($legacyNearbyCards->has($item['id']))
                                    @include('theme::pages.venues.partials.catalog-item', [
                                        'venue' => $legacyNearbyCards->get($item['id']),
                                        'mode' => 'card',
                                        'cardUrl' => $item['url'],
                                        'distanceMeters' => $item['distance_meters'],
                                        'cardCourtName' => $item['court_name'] ?? null,
                                    ])
                                @endif
                            @endforeach
                        </div>
                    @else
                        <p>{{ $legacyNearbySimilar
                            ? 'Похожие площадки не найдены. Измените критерии сходства.'
                            : 'Другие площадки не найдены.' }}</p>
                    @endif
                </section>
                @if($legacyNearbySimilar)
                    <dialog class="venue-nearby__dialog" data-venue-nearby-criteria-dialog aria-labelledby="venue-nearby-criteria-title">
                        <header class="venue-nearby__dialog-header">
                            <h2 id="venue-nearby-criteria-title">Критерии сходства</h2>
                            <button type="button" class="btn btn--secondary btn--sm" data-venue-nearby-close aria-label="Закрыть"><i class="ti ti-x"></i></button>
                        </header>
                        <form method="get" action="{{ request()->url() }}#venue-nearby">
                            <input type="hidden" name="nearby" value="similar">
                            <input type="hidden" name="criteria[]" value="">
                            <div class="venue-nearby__criteria">
                                <p>Выберите признаки, которые должны совпадать у одной и той же игровой зоны.</p>
                                @foreach($legacyCriteriaLabels as $key => $label)
                                    <label class="venue-nearby__check">
                                        <input type="checkbox" name="criteria[]" value="{{ $key }}" @checked(in_array($key, $legacyCriteria, true))>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <footer class="venue-nearby__dialog-actions"><button type="submit" class="btn btn--primary">Применить</button></footer>
                        </form>
                    </dialog>
                @endif
            @endif

            @if($venue->fullDescription)
                <section id="venue-full-description" class="venue-show-section" aria-labelledby="venue-full-description-title">
                    <div class="venue-show-section__heading">
                        <h2 id="venue-full-description-title">О площадке</h2>
                    </div>
                    <div class="venue-show-section__description">
                        {!! app(\App\Modules\Content\Application\Services\ContentBodySanitizer::class)->sanitize((string) $venue->fullDescription) !!}
                    </div>
                </section>
            @endif
        </div>
    @else
        <div class="alert alert-warning" role="alert">
            {{ $error_message }}
        </div>
    @endif
@endsection
