@php
    $title = $category?->name ?? 'Магазин';
    $metaTitle = $title.' · Каталог MSKBA';
    $breadcrumbs = [['label' => 'Магазин', 'url' => route('shop.index')]];
    if ($category) {
        $chain = [];
        $node = $category;
        while ($node) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }
        foreach ($chain as $node) {
            $breadcrumbs[] = ['label' => $node->name, 'url' => route('shop.category', $node->slug)];
        }
    }
    $action = $category ? route('shop.category', $category->slug) : route('shop.index');
    $isList = $filters['view'] === 'list';
    $activeCount = collect(['q', 'tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price'])
        ->filter(fn ($key) => filled($filters[$key] ?? null))->count();
    $viewQuery = request()->except(['view', 'page']);
    $categoryTitle = $title;
    $categoryDescription = $category?->description ?: 'Баскетбольная экипировка, форма и аксессуары.';
    $categoryHeadingId = 'app-shop-title';
    $categoryRootId = 'shop-catalog';
    $categoryRootClass = 'app-shop app-shop--catalog';
    $categoryHeaderClass = 'app-shop__heading';
    $categoryLayoutClass = 'app-shop__layout';
    $categorySidebarClass = 'app-shop__sidebar';
    $categoryMainClass = 'app-shop__main';
    $categoryToolbarClass = 'app-shop__toolbar';
    $categoryItemsClass = 'app-shop__products';
    $categoryItemsListClass = 'app-shop__products--list';
    $categoryView = $isList ? 'list' : 'cards';
    $categoryBaseUrl = $action;
    $categoryViewQuery = $viewQuery;
    $categoryResetUrl = $action.($isList ? '?view=list' : '');
    $categoryEnableSorting = true;
    $categorySortValue = $filters['sort'];
    $categorySidebarLabel = 'Категории и фильтры товаров';
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary app-shop__add" href="{{ route('shop.create') }}">+ Добавить товар</a>
@endsection

@section('category-sidebar')
            <div class="app-shop__sidebar-head"><h2>Категории</h2></div>
            @include('theme::pages.shop.partials.navigation', ['scope' => 'desktop'])
            <div class="app-shop__sidebar-divider"></div>
            <h2 class="app-shop__sidebar-label">Поиск товаров</h2>
            @include('theme::pages.shop.partials.filters', ['formId' => 'shop-filters-desktop'])

@endsection

@section('category-toolbar-count')
    <span class="app-shop__toolbar-count">Показано {{ $products->count() }} из {{ $products->total() }} товаров</span>
@endsection

@section('category-before-items')
            <details class="app-shop__mobile-filters panel">
                <summary>Категории и фильтры @if($activeCount) <span class="badge">{{ $activeCount }}</span> @endif
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="app-shop__mobile-filters-body">
                    <h2>Категории</h2>
                    @include('theme::pages.shop.partials.navigation', ['scope' => 'mobile'])
                    <h2>Поиск товаров</h2>
                    @include('theme::pages.shop.partials.filters', ['formId' => 'shop-filters-mobile'])
                </div>
            </details>


@endsection

@section('category-items')
                @forelse($products as $product)
                    @include('theme::pages.shop.partials.card', ['product' => $product])
                @empty
                    <div class="app-shop__empty panel">
                        <h2>Товары не найдены</h2>
                        <p class="muted">Попробуйте изменить фильтры или выбрать другую категорию.</p>
                        <a class="button secondary" href="{{ $action }}">Сбросить параметры</a>
                    </div>
                @endforelse

@endsection

@section('category-after-items')
            @if($products->hasPages())
                <nav class="app-shop__pagination" aria-label="Страницы товаров">
                    {{ $products->links() }}
                </nav>
            @endif

@endsection
