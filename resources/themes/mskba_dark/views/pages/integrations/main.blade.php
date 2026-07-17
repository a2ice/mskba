@php
    $title = 'Telegram';
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('main');
@endphp

@extends('theme::layouts.telegram')

@section('content')
    <section
        class="integration-main"
        data-telegram-mini-app
        data-telegram-auth-url="{{ route('integrations.telegram.auth') }}"
    >
        <div class="integration-panel">
            <header class="telegram-app-header">
                <a class="telegram-app-header__logo" href="{{ route('welcome') }}" aria-label="MSKBA">
                    <img src="{{ asset('images/logo-header-cropped.png') }}" alt="MSKBA" width="420" height="165">
                </a>

                <button
                    type="button"
                    class="telegram-app-header__burger"
                    aria-label="Открыть меню"
                    aria-expanded="false"
                    aria-controls="telegram-app-menu"
                    data-telegram-menu-toggle
                >
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <nav class="telegram-app-menu" id="telegram-app-menu" aria-label="Навигация Mini App" hidden data-telegram-menu>
                    <a href="{{ route('account') }}">Аккаунт</a>

                    @foreach ($menuItems as $item)
                        @continue(! $item['visible'])

                        @php
                            $visibleChildren = array_values(array_filter(
                                $item['children'] ?? [],
                                fn (array $child): bool => $child['visible'],
                            ));
                        @endphp

                        @if ($visibleChildren !== [])
                            <div class="telegram-app-menu__group">
                                <span class="telegram-app-menu__group-label">{{ $item['label'] }}</span>

                                @foreach ($visibleChildren as $child)
                                    <a href="{{ $child['url'] }}" @class(['is-active' => $child['active']])>
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <a href="{{ $item['url'] }}" @class(['is-active' => $item['active']])>
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>
            </header>

            <h1 class="telegram-app-welcome" data-telegram-status>Проверяем Telegram-подпись и авторизуем пользователя...</h1>

            <div class="telegram-action-dashboard" hidden data-telegram-dashboard>
                <section class="telegram-action-section" aria-labelledby="telegram-games-title">
                    <div class="telegram-action-section__heading">
                        <p class="telegram-action-section__eyebrow">На площадку</p>
                        <h2 id="telegram-games-title">Игры</h2>
                    </div>

                    <div class="telegram-action-grid">
                        <button type="button" class="telegram-action-card" data-telegram-feature-open data-feature-title="Играть">
                            <i class="ti ti-ball-basketball" aria-hidden="true"></i>
                            <span>Играть</span>
                        </button>
                        <button type="button" class="telegram-action-card" data-telegram-feature-open data-feature-title="Новая игра">
                            <i class="ti ti-plus" aria-hidden="true"></i>
                            <span>Новая игра</span>
                        </button>
                    </div>
                </section>

                <section class="telegram-action-section" aria-labelledby="telegram-venues-title">
                    <div class="telegram-action-section__heading">
                        <p class="telegram-action-section__eyebrow">Где играть</p>
                        <h2 id="telegram-venues-title">Площадки</h2>
                    </div>

                    <div class="telegram-action-grid">
                        <button type="button" class="telegram-action-card" data-telegram-venue-search-open>
                            <i class="ti ti-map-pin-search" aria-hidden="true"></i>
                            <span>Найти площадку</span>
                        </button>
                        <button type="button" class="telegram-action-card" data-telegram-venue-create-open>
                            <i class="ti ti-map-pin-plus" aria-hidden="true"></i>
                            <span>Добавить</span>
                        </button>
                    </div>
                </section>
            </div>

            @if($telegramBotUsername)
                <hr>
                <div class="integration-panel__actions">
                    <a href="https://t.me/{{ $telegramBotUsername }}" class="btn btn--secondary btn--sm">Открыть бота</a>
                </div>
            @endif
        </div>

        <div class="telegram-feature-modal" hidden data-telegram-feature-modal>
            <button type="button" class="telegram-feature-modal__backdrop" aria-label="Закрыть окно" data-telegram-feature-close></button>
            <section class="telegram-feature-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="telegram-feature-title">
                <button type="button" class="telegram-feature-modal__close" aria-label="Закрыть окно" data-telegram-feature-close></button>
                <p class="telegram-feature-modal__eyebrow">MSKBA Mini App</p>
                <h2 id="telegram-feature-title" data-telegram-feature-title>Новый раздел</h2>
                <p>Функционал находится в разработке и появится в одном из следующих обновлений.</p>
                <button type="button" class="btn btn--primary btn--sm" data-telegram-feature-close>Понятно</button>
            </section>
        </div>

        <div class="telegram-feature-modal" hidden data-telegram-venue-search-modal>
            <button type="button" class="telegram-feature-modal__backdrop" aria-label="Закрыть окно" data-telegram-venue-search-close></button>
            <section class="telegram-feature-modal__dialog telegram-feature-modal__dialog--form" role="dialog" aria-modal="true" aria-labelledby="telegram-venue-search-title">
                <button type="button" class="telegram-feature-modal__close" aria-label="Закрыть окно" data-telegram-venue-search-close></button>
                <p class="telegram-feature-modal__eyebrow">Площадки</p>
                <h2 id="telegram-venue-search-title">Найти площадку</h2>

                <form method="GET" action="{{ route('venues.search') }}" class="telegram-venue-search" data-telegram-venue-search-form>
                    <label class="telegram-venue-form__field" for="telegramVenueSearchQuery">
                        <span>Поиск</span>
                        <input id="telegramVenueSearchQuery" type="search" name="query" placeholder="Название, адрес или описание">
                    </label>

                    <details class="telegram-venue-search__advanced">
                        <summary>Расширенные фильтры</summary>
                        <div class="telegram-venue-search__filters">
                            <label class="telegram-venue-form__field" for="telegramVenueSearchType">
                                <span>Тип</span>
                                <select id="telegramVenueSearchType" name="type">
                                    <option value="">Все типы</option>
                                    @foreach($venueTypes as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="telegram-venue-form__field" for="telegramVenueSearchMetro">
                                <span>Метро</span>
                                <select id="telegramVenueSearchMetro" name="metro_station_id">
                                    <option value="">Любое метро</option>
                                    @foreach($metros as $metro)
                                        <option value="{{ $metro->id }}">
                                            {{ $metro->name }}@if($metro->lineName) ({{ $metro->lineName }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </details>

                    <div class="telegram-venue-form__message" role="status" data-form-message></div>
                </form>

                <div class="telegram-venue-search__results" aria-live="polite" data-telegram-venue-search-results></div>
            </section>
        </div>

        <div class="telegram-feature-modal" hidden data-telegram-venue-create-modal>
            <button type="button" class="telegram-feature-modal__backdrop" aria-label="Закрыть окно" data-telegram-venue-modal-close></button>
            <section class="telegram-feature-modal__dialog telegram-feature-modal__dialog--form" role="dialog" aria-modal="true" aria-labelledby="telegram-venue-create-title">
                <button type="button" class="telegram-feature-modal__close" aria-label="Закрыть окно" data-telegram-venue-modal-close></button>
                <p class="telegram-feature-modal__eyebrow">Новая площадка</p>
                <h2 id="telegram-venue-create-title">Добавить площадку</h2>

                <form method="POST" action="{{ route('venues.store') }}" class="telegram-venue-form" data-telegram-venue-create-form novalidate>
                    @csrf
                    @include('theme::partials.venues.telegram-form-fields', ['fieldPrefix' => 'telegramVenueCreate'])
                    <div class="telegram-venue-form__message" role="status" data-form-message></div>
                    <button type="submit" class="btn btn--primary btn--sm">Создать</button>
                </form>
            </section>
        </div>

        <div class="telegram-feature-modal" hidden data-telegram-venue-edit-modal>
            <button type="button" class="telegram-feature-modal__backdrop" aria-label="Закрыть окно" data-telegram-venue-modal-close></button>
            <section class="telegram-feature-modal__dialog telegram-feature-modal__dialog--form" role="dialog" aria-modal="true" aria-labelledby="telegram-venue-edit-title">
                <button type="button" class="telegram-feature-modal__close" aria-label="Закрыть окно" data-telegram-venue-modal-close></button>
                <p class="telegram-feature-modal__eyebrow">Площадка создана</p>
                <h2 id="telegram-venue-edit-title">Проверьте данные и отправьте на модерацию</h2>

                <form method="POST" class="telegram-venue-moderation-form" data-telegram-venue-moderation-form>
                    @csrf
                    <div class="telegram-venue-form__message" role="status" data-form-message></div>
                    <button type="submit" class="btn btn--primary btn--sm">Отправить на модерацию</button>
                </form>

                <hr>

                <form method="POST" class="telegram-venue-form" data-telegram-venue-edit-form novalidate>
                    @csrf
                    @method('PUT')
                    @include('theme::partials.venues.telegram-form-fields', [
                        'fieldPrefix' => 'telegramVenueEdit',
                        'includeDescriptions' => true,
                    ])
                    <div class="telegram-venue-form__message" role="status" data-form-message></div>
                    <button type="submit" class="btn btn--secondary btn--sm">Сохранить</button>
                </form>
            </section>
        </div>
    </section>
@endsection
