@php
    $title = $category?->name ?? 'Магазин';
    $metaTitle = $title.' — каталог MSKBA';
    $metaDescription = $category?->description ?: 'Баскетбольная форма, спортивная одежда и экипировка.';
    $breadcrumbs = [['label' => 'Магазин', 'url' => route('shop.index')]];
    if ($category) {
        $chain = [];
        $parent = $category;
        while ($parent) {
            array_unshift($chain, $parent);
            $parent = $parent->parent;
        }
        foreach ($chain as $node) {
            $breadcrumbs[] = ['label' => $node->name, 'url' => route('shop.category', $node->slug)];
        }
    }
    $currentView = $filters['view'];
    $activeFilterCount = collect(['tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price'])
        ->filter(fn ($key) => filled($filters[$key] ?? null))->count();
    $action = $category ? route('shop.category', $category->slug) : route('shop.index');
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'shop',
    'categoryClass' => 'shop-catalog',
    'currentView' => $currentView,
    'filtersInitiallyOpen' => true,
    'sidebarNavigationTitle' => 'Категории',
    'sidebarFiltersTitle' => 'Фильтры товаров',
    'mobileFilterModalId' => 'shop-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.shop.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.shop.partials.catalog-filters', ['formId' => 'shop-filters-desktop'])
@endsection

@section('category-search')
    <form id="shop-catalog-search" method="GET" action="{{ $action }}">
        <label class="catalog-toolbar__search" aria-label="Поиск товаров">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название товара или артикул" data-default-category-search>
        </label>
        @foreach(['tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price', 'sort'] as $key)
            @if(filled($filters[$key] ?? null))
                <input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">
            @endif
        @endforeach
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-results-cards')
    <div class="shop-catalog__summary">{{ $products->total() }} {{ trans_choice('товар|товара|товаров', $products->total()) }}</div>
    <div class="default-category-results--cards shop-catalog__results shop-catalog__results--cards">
        @forelse($products as $product)
            @include('theme::pages.shop.partials.catalog-item', ['mode' => 'card'])
        @empty
            <div class="default-category__empty shop-catalog__empty">
                <i class="ti ti-search-off" aria-hidden="true"></i>
                <strong>Товары не найдены</strong>
                <span>Измените фильтры или выберите другую категорию</span>
                <a class="btn btn--secondary btn--sm" href="{{ $action }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="shop-catalog__summary">{{ $products->total() }} {{ trans_choice('товар|товара|товаров', $products->total()) }}</div>
    <div class="default-category-results--list shop-catalog__results shop-catalog__results--list">
        @forelse($products as $product)
            @include('theme::pages.shop.partials.catalog-item', ['mode' => 'list'])
        @empty
            <div class="default-category__empty shop-catalog__empty">
                <i class="ti ti-search-off" aria-hidden="true"></i>
                <strong>Товары не найдены</strong>
                <span>Измените фильтры или выберите другую категорию</span>
                <a class="btn btn--secondary btn--sm" href="{{ $action }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-pagination')
    @if($products->hasPages())
        <div class="mt-4">{{ $products->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'shop-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-shop-catalog-filters">Фильтры товаров</h2>
        @include('theme::pages.shop.partials.catalog-filters', ['formId' => 'shop-filters-mobile'])
    @endcomponent
@endsection
