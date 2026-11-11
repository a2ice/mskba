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
@endphp

@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])
@section('content')
<section class="container app-shop app-shop--catalog" aria-labelledby="app-shop-title">
    <header class="app-shop__heading">
        <div>
            <h1 id="app-shop-title">{{ $title }}</h1>
            <p class="muted">{{ $category?->description ?: 'Баскетбольная экипировка, форма и аксессуары.' }}</p>
        </div>
        <a class="button primary app-shop__add" href="{{ route('shop.create') }}">+ Добавить товар</a>
    </header>

    <div class="app-shop__layout">
        <aside class="app-shop__sidebar panel" aria-label="Категории и фильтры товаров">
            <div class="app-shop__sidebar-head"><h2>Категории</h2></div>
            @include('theme::pages.shop.partials.navigation', ['scope' => 'desktop'])
            <div class="app-shop__sidebar-divider"></div>
            <h2 class="app-shop__sidebar-label">Поиск товаров</h2>
            @include('theme::pages.shop.partials.filters', ['formId' => 'shop-filters-desktop'])
        </aside>

        <main class="app-shop__main">
            <div class="app-shop__toolbar">
                <span class="app-shop__toolbar-count">Показано {{ $products->count() }} из {{ $products->total() }} товаров</span>
                @include('theme::components.catalog-toolbar', [
                    'baseUrl' => $action,
                    'viewQuery' => $viewQuery,
                    'currentView' => $isList ? 'list' : 'cards',
                    'resetUrl' => $action.($isList ? '?view=list' : ''),
                    'enableSorting' => true,
                    'sortValue' => $filters['sort'],
                ])
            </div>

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

            <div @class(['app-shop__products', 'app-shop__products--list' => $isList])>
                @forelse($products as $product)
                    @include('theme::pages.shop.partials.card', ['product' => $product])
                @empty
                    <div class="app-shop__empty panel">
                        <h2>Товары не найдены</h2>
                        <p class="muted">Попробуйте изменить фильтры или выбрать другую категорию.</p>
                        <a class="button secondary" href="{{ $action }}">Сбросить параметры</a>
                    </div>
                @endforelse
            </div>
            @if($products->hasPages())
                <nav class="app-shop__pagination" aria-label="Страницы товаров">
                    {{ $products->links() }}
                </nav>
            @endif
        </main>
    </div>
</section>
@endsection
