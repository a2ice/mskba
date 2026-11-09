@php
    $title = $category?->name ?? 'Магазин';
    $metaTitle = $title.' — каталог MSKBA';
    $metaDescription = $category?->description ?: 'Товары и спортивная экипировка в каталоге MSKBA.';
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
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'shop',
    'sectionClass' => 'shop-page',
    'contentTitle' => $title,
    'contentSubtitle' => $category?->description ?: 'Экипировка, аксессуары и другие товары. Пока только каталог, без оформления заказа.',
    'sidebarLabel' => 'Разделы магазина',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Категории</h2>
        <nav class="shop-side-nav" aria-label="Категории магазина">
            <a @class(['is-active' => ! $category]) href="{{ route('shop.index') }}">Все товары</a>
            @foreach($categories as $item)
                <a href="{{ route('shop.category', $item->slug) }}">
                    {{ $item->name }}
                    <i class="ti ti-chevron-right" aria-hidden="true"></i>
                </a>
            @endforeach
            @if($category)
                <a class="shop-side-nav__back" href="{{ $category->parent
                    ? route('shop.category', $category->parent->slug)
                    : route('shop.index') }}">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i> На уровень выше
                </a>
            @endif
        </nav>
    </div>
    @if($tags->isNotEmpty())
        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Теги</h2>
            <div class="shop-tags">
                @foreach($tags as $item)
                    <a @class(['shop-tag', 'is-active' => $tag === $item->slug])
                       href="{{ request()->fullUrlWithQuery(['tag' => $tag === $item->slug ? null : $item->slug, 'page' => null]) }}">
                        #{{ $item->name }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@section('section-content')
    <form class="shop-search" method="GET" action="{{ $category
        ? route('shop.category', $category->slug)
        : route('shop.index') }}">
        <label class="shop-search__input">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $search }}" placeholder="Название товара или артикул">
        </label>
        @if($tag)<input type="hidden" name="tag" value="{{ $tag }}">@endif
        <button class="btn btn--primary" type="submit">Найти</button>
        @if($search !== '' || $tag !== '')
            <a class="btn btn--secondary" href="{{ $category
                ? route('shop.category', $category->slug)
                : route('shop.index') }}">Сбросить</a>
        @endif
    </form>

    @if($categories->isNotEmpty())
        <div class="shop-categories">
            @foreach($categories as $item)
                @php($photos = $item->imageUrls())
                <a class="shop-category-card" href="{{ route('shop.category', $item->slug) }}">
                    @if(count($photos))
                        <img src="{{ $photos[0] }}" alt="{{ $item->name }}" loading="lazy">
                    @else
                        <span class="shop-image-placeholder"><i class="ti ti-category-2"></i></span>
                    @endif
                    <strong>{{ $item->name }}</strong>
                    <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    @endif

    <div class="shop-results-meta">{{ $products->total() }} {{ trans_choice('товар|товара|товаров', $products->total()) }}</div>
    <div class="shop-products">
        @forelse($products as $product)
            @php($photos = $product->imageUrls())
            <article class="shop-product-card">
                <a class="shop-product-card__photo" href="{{ route('shop.product', $product->slug) }}">
                    @if(count($photos))
                        <img src="{{ $photos[0] }}" alt="{{ $product->name }}" loading="lazy">
                    @else
                        <span class="shop-image-placeholder"><i class="ti ti-shopping-bag"></i></span>
                    @endif
                </a>
                <div class="shop-product-card__body">
                    @if($product->sku)
                        <span class="shop-product-card__sku">Арт. {{ $product->sku }}</span>
                    @endif
                    <h2><a href="{{ route('shop.product', $product->slug) }}">{{ $product->name }}</a></h2>
                    <div class="shop-product-card__price">
                        @if($product->price_kopecks !== null)
                            <strong>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->price_kopecks) }} ₽</strong>
                            @if($product->old_price_kopecks > $product->price_kopecks)
                                <del>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->old_price_kopecks) }} ₽</del>
                            @endif
                        @else
                            <span>Цена по запросу</span>
                        @endif
                    </div>
                    <div class="shop-product-card__footer">
                        <span @class(['shop-stock', 'is-available' => $product->stock_status === 'in_stock'])>
                            {{ match($product->stock_status) {
                                'in_stock' => 'В наличии',
                                'preorder' => 'Предзаказ',
                                default => 'Нет в наличии',
                            } }}
                        </span>
                        <a class="btn btn--secondary btn--sm" href="{{ route('shop.product', $product->slug) }}">Подробнее</a>
                    </div>
                </div>
            </article>
        @empty
            <div class="shop-empty">
                <i class="ti ti-search-off" aria-hidden="true"></i>
                <h2>Товары не найдены</h2>
                <p>Измените поиск или выберите другую категорию.</p>
            </div>
        @endforelse
    </div>
    @if($products->hasPages())
        <div class="mt-4">{{ $products->links('theme::partials.pagination') }}</div>
    @endif
@endsection
