@php
    $title = 'Площадки';
    $breadcrumbs = [['label' => 'Площадки', 'url' => route('venues')]];
    $viewQuery = request()->except(['view', 'page', 'fragment']);
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])
@section('content')
<section class="container app-venues-catalog" data-venue-infinite-catalog
    data-next-page="{{ $hasMore ? $page + 1 : '' }}" aria-labelledby="venue-catalog-title">
    <header class="app-venues-catalog__header">
        <div>
            <h1 id="venue-catalog-title">Площадки</h1>
            <p>Найдите подходящий зал или уличную площадку для игры.</p>
        </div>
        <a class="button primary" href="{{ route('venues.create') }}">+ Добавить площадку</a>
    </header>
    <div class="app-venues-catalog__layout">
        <aside class="app-venues-catalog__sidebar panel" aria-label="Фильтры площадок">
            <h2>Поиск площадок</h2>
            <form method="GET" action="{{ route('venues') }}" class="app-venues-catalog__filters">
                @if($currentView === 'list')<input type="hidden" name="view" value="list">@endif
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
        </aside>

        <main class="app-venues-catalog__main">
            <div class="app-venues-catalog__toolbar">
                <span role="status" aria-live="polite">Показано <b data-venue-visible-count>{{ count($venues) }}</b> из {{ $total }} площадок</span>
                @include('theme::components.catalog-toolbar', [
                    'baseUrl' => route('venues'),
                    'viewQuery' => $viewQuery,
                    'currentView' => $currentView,
                    'resetUrl' => route('venues', $currentView === 'list' ? ['view' => 'list'] : []),
                    'enableSorting' => false,
                ])
            </div>
            <div @class(['app-venues-catalog__items', 'app-venues-catalog__items--list' => $currentView === 'list']) data-venue-items>
                @include('theme::pages.venues.partials.index-items')
            </div>
            <div class="app-venues-catalog__loader" data-venue-loader hidden role="status" aria-live="polite">
                <span class="image-upload-loading__spinner" aria-hidden="true"></span>
                <span>Загружаем площадки…</span>
            </div>
            <div class="app-venues-catalog__error" data-venue-load-error hidden role="alert">
                Не удалось загрузить следующие площадки.
                <button class="button secondary" type="button" data-venue-retry>Повторить</button>
            </div>
            <div class="app-venues-catalog__sentinel" data-venue-sentinel aria-hidden="true"></div>
        </main>
    </div>
</section>
@endsection
