@php
    $title = 'Секции';
    $metaDescription = 'Баскетбольные секции и тренировки Москвы: тренеры, площадки, расписание и набор участников.';
    $categoryTitle = $title;
    $categoryDescription = 'Баскетбольные секции, тренировки и набор участников.';
    $categoryRootId = 'sections-catalog';
    $categorySidebarLabel = 'Навигация и фильтры спортивных секций';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('sports-sections.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryToolbarCount = 'Секций: '.$sections->total();
    $canonicalUrl = $sections->currentPage() > 1 ? route('sports-sections.index', ['page' => $sections->currentPage()]) : route('sports-sections.index');
    $paginationPrevUrl = $sections->previousPageUrl();
    $paginationNextUrl = $sections->nextPageUrl();
    $catalogItems = app(\App\Modules\SportsSection\Presentation\Catalog\SportsSectionCatalogPresenter::class)->present($sections->getCollection());
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary" href="{{ route('account.sports-sections.create') }}">+ Создать секцию</a>
@endsection

@section('category-sidebar')
    <h2>Секции</h2>
    <nav class="app-category__nav" aria-label="Разделы секций">
        <a href="{{ route('sports-sections.index') }}" @class(['is-active' => !($filters['accepts_requests'] ?? false) && !($filters['recruiting'] ?? false)])>Все секции</a>
        <a href="{{ route('sports-sections.index', ['accepts_requests' => 1]) }}" @class(['is-active' => ($filters['accepts_requests'] ?? false)])>Принимают заявки</a>
        <a href="{{ route('sports-sections.index', ['recruiting' => 1]) }}" @class(['is-active' => ($filters['recruiting'] ?? false)])>Идёт набор</a>
    </nav>
    <div class="app-category__separator" aria-hidden="true"></div>
    <h2>Фильтры</h2>
    @include('theme::pages.sports-sections.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            <h2>Секции</h2>
            <nav class="app-category__nav" aria-label="Разделы секций">
                <a href="{{ route('sports-sections.index') }}" @class(['is-active' => !($filters['accepts_requests'] ?? false) && !($filters['recruiting'] ?? false)])>Все секции</a>
                <a href="{{ route('sports-sections.index', ['accepts_requests' => 1]) }}" @class(['is-active' => ($filters['accepts_requests'] ?? false)])>Принимают заявки</a>
                <a href="{{ route('sports-sections.index', ['recruiting' => 1]) }}" @class(['is-active' => ($filters['recruiting'] ?? false)])>Идёт набор</a>
            </nav>
            <h2>Фильтры</h2>
            @include('theme::pages.sports-sections.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
    @forelse($catalogItems as $item)
        <article class="panel app-category__card">
            <a class="app-category__card-cover" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
                <img loading="lazy" src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}">
            </a>
            <div class="app-category__card-body">
                <div class="app-category__badges">
                    <span>{{ $item['training_mode']['label'] }}</span>
                    <span>{{ $item['format']['label'] }}</span>
                    @if($item['recruitment_text'])<span>{{ $item['recruitment_text'] }}</span>@endif
                </div>
                <h2><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h2>
                <p>{{ $item['venue']['name'] ?? 'Площадка не указана' }}</p>
                <p>{{ $item['participant_count_text'] }}@if($item['capacity'] !== null) / {{ $item['capacity'] }} мест@endif</p>
                <p>{{ $item['pricing_text'] }}</p>
                @if(count($item['teams']) > 0)
                    <p>Команды:
                        @foreach($item['teams'] as $team)
                            <a href="{{ $team['url'] }}">{{ $team['name'] }}</a>@if(!$loop->last), @endif
                        @endforeach
                    </p>
                @endif
                @if($item['description'])<p class="app-category__description">{{ $item['description'] }}</p>@endif
                <a class="button secondary app-category__details" href="{{ $item['url'] }}">Подробнее</a>
            </div>
        </article>
    @empty
        <section class="panel app-category__empty">
            <h2>Секции не найдены</h2>
            <p>Попробуйте изменить условия поиска.</p>
            <a class="button secondary" href="{{ route('sports-sections.index') }}">Сбросить параметры</a>
        </section>
    @endforelse
@endsection

@section('category-after-items')
    @if($sections->hasPages())
        <nav class="app-category__pagination" aria-label="Страницы секций">{{ $sections->links() }}</nav>
    @endif
@endsection
