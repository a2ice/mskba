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
            $breadcrumbs[] = [
                'label' => $node->name,
                'url' => route('shop.category', $node->slug),
            ];
        }
    }
    $action = $category ? route('shop.category', $category->slug) : route('shop.index');
    $isList = $filters['view'] === 'list';
    $activeCount = collect(['tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price'])
        ->filter(fn ($key) => filled($filters[$key] ?? null))->count();
    $viewQuery = request()->except(['view', 'page']);
@endphp

@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-shop" aria-labelledby="app-shop-title">
    <header class="app-shop__heading">
        <div>
            <p class="eyebrow accent">MSKBA / МАГАЗИН</p>
            <h1 id="app-shop-title">{{ $title }}</h1>
            <p class="muted">{{ $category?->description ?: 'Баскетбольная экипировка, форма и аксессуары.' }}</p>
        </div>
        <span class="badge app-shop__catalog-badge">Каталог без оформления заказа</span>
    </header>

    <div class="app-shop__layout">
        <aside class="app-shop__sidebar panel" aria-label="Категории и фильтры магазина">
            <div class="app-shop__sidebar-head"><h2>Категории</h2></div>
            @include('theme::pages.shop.partials.navigation')
            <div class="app-shop__sidebar-divider"></div>
            <h2 class="app-shop__sidebar-label">Фильтры товаров</h2>
            @include('theme::pages.shop.partials.filters', ['formId' => 'shop-filters-desktop'])
        </aside>

        <main class="app-shop__main">
            <div class="app-shop__toolbar panel">
                <form class="app-shop__search" action="{{ $action }}" method="GET" role="search">
                    <label for="app-shop-search" class="sr-only">Поиск товаров</label>
                    <input id="app-shop-search" type="search" name="q" value="{{ $filters['q'] }}"
                           placeholder="Название товара или артикул" autocomplete="off">
                    @foreach(['tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price', 'sort'] as $key)
                        @if(filled($filters[$key] ?? null))
                            <input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">
                        @endif
                    @endforeach
                    @if($isList)<input type="hidden" name="view" value="list">@endif
                    <button class="button primary" type="submit">Найти</button>
                </form>
                <div class="app-shop__view-switch" role="group" aria-label="Вид товаров">
                    <a @class(['is-active' => ! $isList]) href="{{ $action.'?'.http_build_query($viewQuery) }}"
                       aria-label="Карточками" title="Карточками" @if(! $isList) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    </a>
                    <a @class(['is-active' => $isList])
                       href="{{ $action.'?'.http_build_query(array_merge($viewQuery, ['view' => 'list'])) }}"
                       aria-label="Списком" title="Списком" @if($isList) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
                    </a>
                </div>
            </div>

            <details class="app-shop__mobile-filters panel">
                <summary>Категории и фильтры @if($activeCount) <span class="badge">{{ $activeCount }}</span> @endif
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="app-shop__mobile-filters-body">
                    <h2>Категории</h2>
                    @include('theme::pages.shop.partials.navigation')
                    <h2>Фильтры</h2>
                    @include('theme::pages.shop.partials.filters', ['formId' => 'shop-filters-mobile'])
                </div>
            </details>

            <div class="app-shop__results-head">
                <span>{{ $products->total() }} {{ trans_choice('товар|товара|товаров', $products->total()) }}</span>
                @if($activeCount)
                    <a href="{{ $action }}" class="app-shop__clear-filters">Сбросить фильтры</a>
                @endif
            </div>

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
