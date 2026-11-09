@php
    $title = $product->name;
    $metaTitle = $product->name.' — магазин MSKBA';
    $metaDescription = \Illuminate\Support\Str::limit(trim(strip_tags((string) $product->description)), 160) ?: $product->name;
    $metaImage = $product->imageUrls()[0] ?? null;
    $photos = $product->imageUrls();
    $breadcrumbs = [['label' => 'Магазин', 'url' => route('shop.index')]];
    if ($category = $visibleCategories->first()) {
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
    $breadcrumbs[] = ['label' => $product->name];
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'shop-product',
    'sectionClass' => 'shop-page',
    'contentTitle' => $title,
    'sidebarLabel' => 'Категории товара',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Магазин</h2>
        <nav class="shop-side-nav" aria-label="Навигация магазина">
            <a href="{{ route('shop.index') }}">Все товары</a>
            @foreach($visibleCategories as $category)
                <a href="{{ route('shop.category', $category->slug) }}">{{ $category->name }}
                    <i class="ti ti-chevron-right" aria-hidden="true"></i>
                </a>
            @endforeach
        </nav>
    </div>
    @if($product->tags->isNotEmpty())
        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Теги</h2>
            <div class="shop-tags">
                @foreach($product->tags as $tag)
                    <a class="shop-tag" href="{{ route('shop.index', ['tag' => $tag->slug]) }}">#{{ $tag->name }}</a>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@section('section-content')
    <div class="shop-product-detail">
        <div class="shop-product-detail__gallery">
            @if(count($photos))
                <div class="shop-product-detail__main-photo">
                    <img src="{{ $photos[0] }}" alt="{{ $product->name }}">
                </div>
                @if(count($photos) > 1)
                    <div class="shop-product-detail__thumbs">
                        @foreach($photos as $photo)
                            <a href="{{ $photo }}" target="_blank" rel="noopener"
                               aria-label="Открыть фотографию {{ $loop->iteration }}">
                                <img src="{{ $photo }}" alt="{{ $product->name }} — фото {{ $loop->iteration }}" loading="lazy">
                            </a>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="shop-product-detail__main-photo shop-image-placeholder">
                    <i class="ti ti-shopping-bag" aria-hidden="true"></i>
                </div>
            @endif
        </div>
        <div class="shop-product-detail__summary">
            @if($product->sku)<div class="shop-product-card__sku">Артикул: {{ $product->sku }}</div>@endif
            <div class="shop-product-detail__prices">
                @if($product->price_kopecks !== null)
                    <strong>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->price_kopecks) }} ₽</strong>
                    @if($product->old_price_kopecks > $product->price_kopecks)
                        <del>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->old_price_kopecks) }} ₽</del>
                    @endif
                @else
                    <span>Цена по запросу</span>
                @endif
            </div>
            <div class="shop-product-detail__stock">
                <span @class(['shop-stock', 'is-available' => $product->stock_status === 'in_stock'])>
                    {{ match($product->stock_status) {
                        'in_stock' => 'На складе',
                        'preorder' => 'Предзаказ',
                        'on_request' => 'Наличие уточняется',
                        default => 'Нет в наличии',
                    } }}
                </span>
                @if($product->stock_status === 'in_stock')
                    <span>Остаток: {{ $product->stock_quantity }} шт.</span>
                @endif
            </div>
            <p class="shop-product-detail__notice">Сейчас магазин работает в режиме каталога. Покупка и оформление заказа пока недоступны.</p>
        </div>
    </div>

    @if($product->description)
        <div class="shop-product-detail__section">
            <h2>Описание</h2>
            <div class="shop-product-detail__description">{{ $product->description }}</div>
        </div>
    @endif
    @if($characteristics->isNotEmpty())
        <div class="shop-product-detail__section">
            <h2>Характеристики</h2>
            <dl class="shop-specs">
                @foreach($characteristics as $item)
                    <div>
                        <dt>{{ $item->attribute?->name }}</dt>
                        <dd>
                            @if($item->attribute?->type === 'boolean')
                                {{ $item->value === '1' ? 'Да' : 'Нет' }}
                            @else
                                {{ $item->value }} {{ $item->attribute?->unit }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif
    <a class="btn btn--secondary mt-4" href="{{ route('shop.index') }}"><i class="ti ti-arrow-left" aria-hidden="true"></i> В каталог</a>
@endsection
