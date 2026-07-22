@php
    if(isset($venue)) {
        $title = "{$venue->name}";
        $address = $venue->address;
        $displayAddress = $address?->display ?: $venue->rawAddress;
        $hasCoordinates = $address?->latitude && $address?->longitude;
        $hasMap = $hasCoordinates && $venue->about->mapApiKey;
        $nearestMetro = $venue->metroStations[0] ?? null;
        $yandexMapUrl = $displayAddress
            ? 'https://yandex.ru/maps/?text=' . urlencode($displayAddress)
            : null;
        $shortWeekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $shortMonths = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн', 7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];
        $placeholderDateLabel = static fn ($date) => $date->format('d') . ' ' . $shortMonths[(int) $date->month];
        $placeholderWeekdayLabel = static fn ($date) => $shortWeekdays[(int) $date->dayOfWeekIso];
        $ratingPercent = max(0, min(100, (($venue->about->rating ?? 0) / 5) * 100));
        $ratingLabel = $venue->about->rating
            ? 'Рейтинг: ' . number_format($venue->about->rating, 1, ',', ' ') . ' из 5'
            : 'Рейтинг пока не сформирован';
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
    'contentTitle' => isset($venue) ? $venue->name : 'Площадка',
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
            @if($venue->canEdit)
                <div class="venue-management-actions">
                    <a href="{{ route('venues.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Редактировать</a>
                    <a href="{{ route('venues.status', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Статус</a>
                </div>
            @else
                <p class="section-sidebar-block__text">
                    Доступные действия появятся здесь, если у пользователя есть права на управление площадкой.
                </p>
            @endif
        </div>
    @else
        <div class="section-sidebar-block">
            @include('theme::partials.menu.sidebar', ['page' => 'venues', 'sidebarTitle' => 'Площадки'])
        </div>
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
                default => ['modifier' => 'unconfirmed', 'icon' => 'ti-help'],
            };
        @endphp
        <span
            class="venue-heading-status venue-heading-status--{{ $headingStatus['modifier'] }}"
            role="img"
            aria-label="Статус площадки: {{ $venue->status }}"
            title="{{ $venue->status }}"
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
            <button type="button" class="btn btn--primary btn--sm venue-booking-action" aria-label="Забронировать" disabled>
                <i class="ti ti-player-play venue-booking-action__icon" aria-hidden="true"></i>
                <span class="venue-booking-action__label">Забронировать</span>
            </button>
        </div>
    @endif
@endsection

@section('section-content')
    @if(!empty($venue))
        <div class="venue-show">
            <section class="venue-hero" aria-label="Краткая информация">
                <div class="venue-hero__media">
                    @if($venue->featuredMedia !== [])
                        <img
                            src="{{ $venue->featuredMedia[0]['url'] }}"
                            alt="{{ $venue->featuredMedia[0]['title'] ?: $venue->name }}"
                            data-venue-hero-image
                        >
                    @else
                        <div class="venue-hero__placeholder">
                            <img src="{{ asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $venue->name }}" data-venue-hero-image>
                        </div>
                    @endif

                    <div class="venue-hero-thumbnails" aria-label="Фотографии площадки">
                        @forelse($venue->featuredMedia as $index => $media)
                            <button
                                type="button"
                                @class(['venue-hero-thumbnail', 'is-active' => $index === 0])
                                data-venue-hero-thumbnail
                                data-url="{{ $media['url'] }}"
                                data-alt="{{ $media['title'] ?: $venue->name }}"
                                aria-label="Показать фото {{ $index + 1 }}"
                                aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"
                            >
                                <img src="{{ $media['url'] }}" alt="">
                            </button>
                        @empty
                            <button
                                type="button"
                                class="venue-hero-thumbnail is-active"
                                data-venue-hero-thumbnail
                                data-url="{{ asset('images/venue-placeholder.png') }}"
                                data-alt="Фото площадки {{ $venue->name }}"
                                aria-label="Показать фото площадки"
                                aria-pressed="true"
                            >
                                <img src="{{ asset('images/venue-placeholder.png') }}" alt="">
                            </button>
                        @endforelse
                    </div>
                </div>

                <div class="venue-hero__summary">
                    <div class="venue-hero__status-row">
                        <span class="venue-pill">{{ $venue->type }}</span>
                    </div>

                    <div @class(['venue-opening-state', 'is-open' => $venue->isOpen, 'is-closed' => ! $venue->isOpen])>
                        <span class="venue-opening-state__dot" aria-hidden="true"></span>
                        <strong>{{ $venue->isOpen ? 'Открыта' : 'Закрыта' }}</strong>
                    </div>

                    <div class="venue-hero__details">
                        <div>
                            <span class="venue-hero__detail-label">Адрес</span>
                            @if ($displayAddress)
                                <p class="venue-hero__text">{{ $displayAddress }} <br><a href="#address" class="venue-hero__map-link fc-link">На карте</a></p>
                            @else
                                <p class="venue-hero__text">Адрес пока не указан.</p>
                            @endif
                        </div>
                        <div>
                            <span class="venue-hero__detail-label">Ближайшее метро</span>
                            <p class="venue-hero__text">
                                @if($nearestMetro)
                                    {{ $nearestMetro->name }}@if($nearestMetro->lineName), {{ $nearestMetro->lineName }}@endif
                                    @if($nearestMetro->walkingTimeMinutes) · {{ $nearestMetro->walkingTimeMinutes }} мин пешком @endif
                                @else
                                    Ближайшее метро пока не указано.
                                @endif
                            </p>
                        </div>
                        <div>
                            <span class="venue-hero__detail-label">Часы работы</span>
                            <p class="venue-hero__text">{{ $venue->todayHours }}</p>
                        </div>
                        <div>
                            <span class="venue-hero__detail-label">Текущие слоты</span>
                            <p class="venue-hero__text">Свободные слоты появятся после запуска бронирования.</p>
                        </div>
                    </div>
                </div>
            </section>

            <nav class="venue-anchor-nav" aria-label="Быстрая навигация" data-venue-anchor-nav>
                @foreach($venue->sections as $section)
                    <a href="#{{ $section['id'] }}" @class(['venue-anchor-nav__link', 'is-muted' => ! $section['isAvailable']]) data-venue-anchor-link>
                        {{ $section['label'] }}
                    </a>
                @endforeach
            </nav>

            @if($venue->fullDescription)
                <section id="venue-full-description" class="venue-show-section" aria-labelledby="venue-full-description-title">
                    <div class="venue-show-section__heading">
                        <h2 >О площадке</h2>
                    </div>
                    <p>{{ $venue->fullDescription }}</p>
                </section>
            @endif

            @if($venue->featuredMedia !== [])
                <section id="gallery" class="venue-show-section">
                    <div class="venue-show-section__heading">
                        <h2>Галерея</h2>
                        <span class="venue-section-state">{{ count($venue->featuredMedia) }} фото</span>
                    </div>

                    <div class="venue-gallery">
                        @foreach($venue->featuredMedia as $index => $media)
                            <figure class="venue-gallery__item">
                                <button
                                    type="button"
                                    class="venue-gallery__button"
                                    data-venue-gallery-item
                                    data-index="{{ $index }}"
                                    data-url="{{ $media['url'] }}"
                                    data-title="{{ $media['title'] ?: $venue->name }}"
                                    data-description="{{ $media['description'] ?: '' }}"
                                >
                                    <img src="{{ $media['url'] }}" alt="{{ $media['title'] ?: $venue->name }}">
                                </button>
                                @if($media['title'] || $media['description'])
                                    <figcaption>
                                        @if($media['title'])
                                            <strong>{{ $media['title'] }}</strong>
                                        @endif
                                        @if($media['description'])
                                            <span>{{ $media['description'] }}</span>
                                        @endif
                                    </figcaption>
                                @endif
                            </figure>
                        @endforeach
                    </div>

                    <div class="venue-gallery-modal" data-venue-gallery-modal hidden>
                        <div class="venue-gallery-modal__backdrop" data-venue-gallery-close></div>
                        <section class="venue-gallery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-gallery-modal-title">
                            <button type="button" class="venue-gallery-modal__close" data-venue-gallery-close aria-label="Закрыть">
                                <i class="ti ti-x"></i>
                            </button>

                            <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--prev" data-venue-gallery-prev aria-label="Предыдущее фото">
                                <i class="ti ti-chevron-left"></i>
                            </button>

                            <img src="" alt="" data-venue-gallery-image>

                            <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--next" data-venue-gallery-next aria-label="Следующее фото">
                                <i class="ti ti-chevron-right"></i>
                            </button>

                            <div class="venue-gallery-modal__caption">
                                <h3 id="venue-gallery-modal-title" data-venue-gallery-title></h3>
                                <p data-venue-gallery-description></p>
                            </div>
                        </section>
                    </div>
                </section>
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
                                    <span class="venue-metro__bullet" style="background-color: {{ $lineColor }}"></span>
                                    <span class="venue-metro__name">{{ $station->name }}</span>
                                    @if($station->lineName)
                                        <span class="venue-metro__line">{{ $station->lineName }}</span>
                                    @endif
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

                        <button type="button" class="venue-address-card__nearby" data-venue-nearby-open>
                            Площадки рядом
                        </button>
                    </div>
                </div>

                <div class="venue-day-modal" data-venue-nearby-modal hidden>
                    <div class="venue-day-modal__backdrop" data-venue-nearby-close></div>
                    <section class="venue-day-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-nearby-modal-title">
                        <div class="venue-day-modal__head">
                            <div>
                                <p class="venue-day-modal__eyebrow">Рядом с вами</p>
                                <h3 id="venue-nearby-modal-title">Площадки рядом</h3>
                            </div>
                            <button type="button" class="venue-day-modal__close" data-venue-nearby-close aria-label="Закрыть">
                                <i class="ti ti-x"></i>
                            </button>
                        </div>
                        <div class="venue-day-modal__body" data-venue-nearby-results>
                            <div class="venue-day-modal__notice">
                                Список ближайших площадок появится после подключения AJAX-загрузки.
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

            <section id="schedule" class="venue-show-section">
                <div class="venue-show-section__heading">
                    <div>
                        <h2>Расписание</h2>
                        <p class="venue-show-section__caption">Нажмите на день, чтобы посмотреть интервалы.</p>
                    </div>
                    <span class="venue-section-state">{{ $venue->about->scheduleDays === [] ? 'заглушка' : '14 дней' }}</span>
                </div>

                @if($venue->about->scheduleDays !== [])
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
                                            <strong>{{ $interval['startsAt'] }}-{{ $interval['endsAt'] }}</strong>
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
                            <div class="venue-schedule-preview__day is-closed">
                                <div>
                                    <span>{{ $placeholderDateLabel($placeholderDate) }}</span>
                                    <small>{{ $placeholderWeekdayLabel($placeholderDate) }}</small>
                                </div>
                                <strong>Нет данных</strong>
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
        </div>
    @else
        <div class="alert alert-warning" role="alert">
            {{ $error_message }}
        </div>
    @endif
@endsection
