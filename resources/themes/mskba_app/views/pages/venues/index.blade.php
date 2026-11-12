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
    $categoryBaseUrl = route('venues');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = route('venues', $currentView === 'list' ? ['view' => 'list'] : []);
    $categoryEnableSorting = true;
    $categorySortValue = $filters['sort'] ?? '';
    $categorySortChoices = [
        '' => 'По названию (А–Я)',
        'name_desc' => 'По названию (Я–А)',
        'new' => 'Сначала новые',
    ];
    $categorySidebarLabel = 'Фильтры площадок';
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary" href="{{ route('venues.create') }}">+ Добавить площадку</a>
@endsection

@section('category-sidebar')
            <h2>Поиск площадок</h2>
            <form method="GET" action="{{ route('venues') }}" class="app-venues-catalog__filters">
                @if($currentView === 'list')<input type="hidden" name="view" value="list">@endif
                @if(filled($filters['sort'] ?? null))<input type="hidden" name="sort" value="{{ $filters['sort'] }}">@endif
                <label><span>Поиск площадки</span>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, адрес или описание">
                </label>
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
    <span role="status" aria-live="polite">Показано <b data-venue-visible-count>{{ count($venues) }}</b> из {{ $total }} площадок</span>
@endsection

@section('category-items')
    @include('theme::pages.venues.partials.index-items')
@endsection

@section('category-after-items')
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
