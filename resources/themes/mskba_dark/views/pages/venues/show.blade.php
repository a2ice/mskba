@php
    if(isset($venue)) {
        $title = "{$venue->name}";
        $address = $venue->address;
        $displayAddress = $address?->display ?: $venue->rawAddress;
        $hasCoordinates = $address?->latitude && $address?->longitude;
        $hasMap = $hasCoordinates && $venue->about->mapApiKey;
        $yandexMapUrl = $displayAddress
            ? 'https://yandex.ru/maps/?text=' . urlencode($displayAddress)
            : null;
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
    'contentSubtitle' => isset($venue) ? $venue->description : null,
    'sidebarLabel' => 'Навигация площадки',
])

@section('section-sidebar')
    @if(!empty($venue))
        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Площадка</h2>
            <nav class="venue-side-nav" aria-label="Разделы площадки">
                @foreach($venue->sections as $section)
                    <a href="#{{ $section['id'] }}" @class(['venue-side-nav__link', 'is-muted' => ! $section['isAvailable']])>
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
                <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn--secondary btn--sm">Редактировать</a>
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

@section('section-heading-action')
    @if(!empty($venue))
        <div class="venue-heading-actions">
            <a href="{{ route('venues') }}" class="btn btn--secondary btn--sm">К списку</a>
            @if($venue->canEdit)
                <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn--primary-bordered btn--sm">Редактировать</a>
            @endif
        </div>
    @endif
@endsection

@section('section-content')
    @if(!empty($venue))
        <div class="venue-show">
            <section class="venue-hero" aria-label="Краткая информация">
                <div class="venue-hero__media">
                    @if($venue->featuredMedia !== [])
                        <img src="{{ $venue->featuredMedia[0]['url'] }}" alt="{{ $venue->featuredMedia[0]['title'] ?: $venue->name }}">
                    @else
                        <div class="venue-hero__placeholder">
                            <span class="venue-hero__placeholder-kicker">{{ $venue->type }}</span>
                            <span class="venue-hero__placeholder-title">{{ $venue->name }}</span>
                        </div>
                    @endif
                </div>

                <div class="venue-hero__summary">
                    <div class="venue-hero__status-row">
                        <span class="venue-pill">{{ $venue->type }}</span>
                        <span class="venue-pill venue-pill--muted">{{ $venue->status }}</span>
                    </div>

                    <div class="venue-rating">
                        <span class="venue-rating__value">{{ $venue->about->rating ? number_format($venue->about->rating, 1, ',', ' ') : '—' }}</span>
                        <span class="venue-rating__caption">
                            {{ $venue->about->ratingCount ? $venue->about->ratingCount . ' оценок' : 'Рейтинг появится после запуска отзывов' }}
                        </span>
                    </div>

                    <p class="venue-hero__text">
                        {{ $venue->description ?: 'Описание площадки будет дополнено после модерации и заполнения профиля площадки.' }}
                    </p>

                    <div class="venue-hero__actions">
                        @if($venue->about->scheduleDays !== [])
                            <button type="button" class="btn btn--primary btn--sm" data-venue-scroll-target="schedule">
                                Выбрать время
                            </button>
                        @else
                            <button type="button" class="btn btn--primary btn--sm" disabled>
                                Расписание готовится
                            </button>
                        @endif

                        @if($yandexMapUrl)
                            <a href="{{ $yandexMapUrl }}" class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                                Маршрут
                            </a>
                        @endif
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
                    @if($yandexMapUrl)
                        <a href="{{ $yandexMapUrl }}" class="venue-show-section__link" target="_blank" rel="noopener">
                            Открыть в Яндекс Картах
                        </a>
                    @endif
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
                                <dd>{{ $displayAddress ?: 'Адрес пока не указан' }}</dd>
                            </div>

                            @if($address?->postalCode)
                                <div>
                                    <dt>Индекс</dt>
                                    <dd>{{ $address->postalCode }}</dd>
                                </div>
                            @endif
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
                    </div>
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
                            <div class="venue-schedule-preview__day is-closed">
                                <div>
                                    <span>{{ now()->addDays($day)->translatedFormat('d M') }}</span>
                                    <small>{{ now()->addDays($day)->translatedFormat('D') }}</small>
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
