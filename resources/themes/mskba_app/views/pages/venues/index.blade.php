@php
    $title = 'Площадки';
    $breadcrumbs = [['label' => 'Площадки', 'url' => route('venues')]];
    $categoryTitle = $title;
    $categoryDescription = 'Найдите подходящий зал или уличную площадку для игры.';
    $categoryHeadingId = 'venue-catalog-title';
    $categoryRootId = 'venues-catalog';
    $categoryRootClass = 'app-venues-catalog';
    $categoryHeaderClass = 'app-venues-catalog__header';
    $categoryLayoutClass = 'app-venues-catalog__layout';
    $categorySidebarClass = 'app-venues-catalog__sidebar';
    $categoryMainClass = 'app-venues-catalog__main';
    $categoryToolbarClass = 'app-venues-catalog__toolbar';
    $categoryItemsClass = 'app-venues-catalog__items';
    $categoryItemsListClass = 'app-venues-catalog__items--list';
    $categoryView = $currentView;
    $categoryHasMap = true;
    $categoryBaseUrl = route('venues');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = route('venues', in_array($currentView, ['list', 'map'], true) ? ['view' => $currentView] : []);
    $categoryEnableSorting = true;
    $categorySortValue = $filters['sort'] ?? '';
    $categorySortChoices = [
        '' => 'По названию (А–Я)',
        'name_desc' => 'По названию (Я–А)',
        'new' => 'Сначала новые',
    ];
    $categorySidebarLabel = 'Фильтры площадок';
    $categoryResultTotal = $total;
    $venuePageQuery = array_filter(
        \Illuminate\Support\Arr::except($filters, ['page', 'fragment']),
        fn ($value) => $value !== null && $value !== '',
    );
    $venuePageUrl = fn (int $number) => route('venues', $venuePageQuery + ($number > 1 ? ['page' => $number] : []));
    $paginationPrevUrl = $currentView !== 'map' && $page > 1 ? $venuePageUrl($page - 1) : null;
    $paginationNextUrl = $currentView !== 'map' && $hasMore ? $venuePageUrl($page + 1) : null;
    // Only the default catalog's numbered pages are new indexable targets.
    // Existing facet indexing policy is not changed by this pagination patch.
    if ($venuePageQuery === [] && $page > 1) {
        $canonicalUrl = $venuePageUrl($page);
        $metaTitle = 'Баскетбольные площадки Москвы — страница '.$page.' | MSKBA';
    }
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary app-venues-catalog__create" href="{{ route('venues.create') }}" aria-label="Добавить площадку" title="Добавить площадку">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span>Добавить площадку</span>
    </a>
@endsection

@section('category-sidebar')
            <h2>Поиск площадок</h2>
            <form method="GET" action="{{ route('venues') }}" class="app-venues-catalog__filters">
                @if(in_array($currentView, ['list', 'map'], true))<input type="hidden" name="view" value="{{ $currentView }}">@endif
                @if(filled($filters['sort'] ?? null))<input type="hidden" name="sort" value="{{ $filters['sort'] }}">@endif
                <label><span>Поиск площадки</span>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, адрес или описание">
                </label>
                @include('components.venue-catalog-geography-filter', ['geographyTheme' => 'app'])
                @include('components.venue-catalog-nearby-filter', ['scope' => 'app'])
                <div data-catalog-range-filter>
                    <label class="check-label">
                        <input type="checkbox" name="has_upcoming_events" value="1" data-catalog-range-toggle
                            aria-controls="venue-event-days-filter" @checked($filters['has_upcoming_events'] ?? false)>
                        <span>Есть ближайшие мероприятия</span>
                    </label>
                    <label class="app-player-workspace__field app-player-workspace__range-field" for="venue-event-days"
                        id="venue-event-days-filter" data-catalog-range-options @if(!($filters['has_upcoming_events'] ?? false)) hidden @endif>
                        <span class="app-player-workspace__range-heading">Период, дней <output for="venue-event-days" data-catalog-range-value>{{ $filters['event_days'] ?? 7 }}</output></span>
                        <input id="venue-event-days" type="range" name="event_days" min="1" max="14" step="1"
                            value="{{ $filters['event_days'] ?? 7 }}" data-catalog-range-input @disabled(!($filters['has_upcoming_events'] ?? false))>
                        <span class="app-player-workspace__range-bounds" aria-hidden="true"><span>1</span><span>14</span></span>
                    </label>
                </div>
                <div class="app-venues-catalog__interest-filter" data-venue-interest-filter>
                    <div class="app-venues-catalog__interest-label">
                        <label class="check-label">
                            <input type="checkbox" name="my_interests" value="1" data-venue-interest-toggle
                                @checked($filters['my_interests'] ?? false) @guest disabled @endguest>
                            <span>Мои интересы</span>
                        </label>
                        <button class="app-venues-catalog__interest-help" type="button"
                            data-venue-interest-help aria-expanded="false"
                            aria-controls="venue-interest-help" aria-label="Что такое мои интересы?">?</button>
                        <span class="app-venues-catalog__interest-tooltip" id="venue-interest-help"
                            role="tooltip" hidden>Показывать площадки, подходящие под мои сохранённые интересы. Без выбора конкретного интереса учитываются все активные.</span>
                    </div>
                    @guest
                        <button type="button" class="app-venues-catalog__interest-login" data-auth-trigger>
                            Войдите, чтобы подобрать площадки по своим интересам
                        </button>
                    @else
                        <div class="app-venues-catalog__interest-options" data-venue-interest-options
                             @unless($filters['my_interests'] ?? false) hidden @endunless>
                            <label for="venue-my-interest-select"><span>Выберите интерес</span>
                                <span class="select-control">
                                    <select id="venue-my-interest-select" name="interest_id" data-venue-interest-select
                                            @unless($filters['my_interests'] ?? false) disabled @endunless>
                                        <option value="">Не выбрано</option>
                                        @foreach($myInterests as $interest)
                                            <option value="{{ $interest->id }}"
                                                @selected((int) ($filters['interest_id'] ?? 0) === (int) $interest->id)>
                                                {{ $interest->name ?: ($interest->venue?->name ?? 'Интерес №'.$interest->id) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </span>
                            </label>
                            <div class="app-venues-catalog__interest-create-row">
                                <button type="button" class="app-venues-catalog__interest-create"
                                        data-subscription-create>+ Добавить интерес</button>
                                <button class="app-venues-catalog__interest-help" type="button"
                                    data-venue-interest-help aria-expanded="false"
                                    aria-controls="venue-interest-create-help"
                                    aria-label="Как добавить интерес?">?</button>
                                <span class="app-venues-catalog__interest-tooltip" id="venue-interest-create-help"
                                      role="tooltip" hidden>Создайте свою подборку площадок по желаемым условиям — например, месту, формату и удобствам.</span>
                            </div>
                            @if($myInterests->isEmpty())
                                <p class="app-venues-catalog__interest-empty">Пока нет сохранённых интересов. Добавьте первый, чтобы получить подборку.</p>
                            @endif
                        </div>
                    @endguest
                </div>
                <label><span>Тип площадки</span>
                    <span class="select-control"><select name="type"><option value="">Все типы</option>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
                </label>
                <label><span>Состояние</span>
                    <span class="select-control"><select name="operational_status"><option value="">Любое</option>
                        @foreach($operationalStatuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['operational_status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
                </label>
                <label><span>Условия доступа</span>
                    <span class="select-control"><select name="access">
                        <option value="">Любые</option>
                        <option value="free" @selected(($filters['access'] ?? '') === 'free')>Бесплатно</option>
                        <option value="paid" @selected(($filters['access'] ?? '') === 'paid')>Платно</option>
                        <option value="approval" @selected(($filters['access'] ?? '') === 'approval')>По подтверждению</option>
                    </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
                </label>
                <div class="app-venues-catalog__filter-actions">
                    <button class="button primary" type="submit">Применить</button>
                </div>
            </form>

@endsection

@section('category-toolbar-count')
    <span role="status" aria-live="polite">Показано <b data-venue-visible-count>{{ $currentView === 'map' ? count($mapPoints) : count($venues) }}</b> из {{ $total }} площадок</span>
@endsection

@section('category-items')
    @include('theme::pages.venues.partials.index-items')
@endsection

@section('category-after-items')
    @if($paginationPrevUrl || $paginationNextUrl)
        <nav class="app-category__pagination" data-venue-page-navigation aria-label="Страницы площадок">
            @if($paginationPrevUrl)
                <a class="button secondary" href="{{ $paginationPrevUrl }}" rel="prev">Предыдущая страница</a>
            @endif
            @if($paginationNextUrl)
                <a class="button secondary" href="{{ $paginationNextUrl }}" rel="next" data-venue-next-page>Следующая страница</a>
            @endif
        </nav>
    @endif
    <div class="app-venues-catalog__loader" data-venue-loader hidden role="status" aria-live="polite">
                <span class="image-upload-loading__spinner" aria-hidden="true"></span>
                <span>Загружаем площадки…</span>
            </div>
            <div class="app-venues-catalog__error" data-venue-load-error hidden role="alert">
                Не удалось загрузить следующие площадки.
                <button class="button secondary" type="button" data-venue-retry>Повторить</button>
            </div>
            <div class="app-venues-catalog__sentinel" data-venue-sentinel aria-hidden="true"></div>

@endsection

@section('category-map')
    <section class="app-venues-catalog__map panel" data-venue-catalog-map-frame aria-label="Карта площадок">
        @if($mapPoints !== [])
            <div class="app-venues-catalog__map-canvas" data-venue-catalog-map
                 data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <p class="app-venues-catalog__map-status" data-venue-catalog-map-status role="status">Загружаем карту…</p>
            <script type="application/json" data-venue-catalog-map-points>@json($mapPoints)</script>
        @else
            <div class="app-venues-catalog__map-empty">
                <strong>Нет площадок с координатами</strong>
                <span>Попробуйте изменить условия поиска или переключиться на карточки.</span>
            </div>
        @endif
    </section>
@endsection

@section('category-before-items')
    @include('theme::partials.venue-map-dialog', ['mapShowCurrentVenueLink' => true])
@endsection
