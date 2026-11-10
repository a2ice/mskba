@php
    $title = $product->name;
    $metaTitle = $product->name.' · Магазин MSKBA';
    $photos = $product->imageUrls();
    $breadcrumbs = [['label' => 'Магазин', 'url' => route('shop.index')]];
    $current = $visibleCategories->first();
    if ($current) {
        $chain = [];
        while ($current) {
            array_unshift($chain, $current);
            $current = $current->parent;
        }
        foreach ($chain as $node) {
            $breadcrumbs[] = [
                'label' => $node->name,
                'url' => route('shop.category', $node->slug),
            ];
        }
    }
    $breadcrumbs[] = ['label' => $product->name, 'url' => null];
@endphp

@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-shop app-shop--detail" aria-labelledby="app-shop-product-title">
    <header class="app-shop__heading">
        <div>
            <p class="eyebrow accent">MSKBA / МАГАЗИН / {{ $product->productType?->name ?? 'ТОВАР' }}</p>
            <h1 id="app-shop-product-title">{{ $product->name }}</h1>
            @if($product->sku)<p class="muted">Артикул: {{ $product->sku }}</p>@endif
        </div>
        <a class="button secondary" href="{{ $visibleCategories->first()
            ? route('shop.category', $visibleCategories->first()->slug) : route('shop.index') }}">К каталогу</a>
    </header>

    <div class="app-shop__product-layout">
        <div class="app-shop__gallery">
            <div class="app-shop__gallery-stage panel">
                @if(count($photos))
                    <img src="{{ $photos[0] }}" alt="{{ $product->name }}" fetchpriority="high">
                @else
                    <div class="app-shop__photo-empty">Нет фотографии</div>
                @endif
            </div>
            @if(count($photos) > 1)
                <div class="app-shop__gallery-thumbs">
                    @foreach($photos as $photo)
                        <a href="{{ $photo }}" target="_blank" rel="noopener"
                           title="Открыть фотографию {{ $loop->iteration }}">
                            <img src="{{ $photo }}" alt="{{ $product->name }} — фото {{ $loop->iteration }}" loading="lazy">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <aside class="app-shop__product-info panel" aria-label="Информация о товаре">
            @if($product->productType)
                <span class="badge">{{ $product->productType->name }}</span>
            @endif

            <div class="app-shop__product-price">
                @if($product->price_kopecks !== null)
                    <strong>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->price_kopecks) }} ₽</strong>
                    @if($product->old_price_kopecks > $product->price_kopecks)
                        <del>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->old_price_kopecks) }} ₽</del>
                    @endif
                @else
                    <span>Цена по запросу</span>
                @endif
            </div>

            <p @class(['app-shop__stock', 'is-available' => $product->stock_status === 'in_stock'])>
                {{ match($product->stock_status) {
                    'in_stock' => 'В наличии',
                    'on_request' => 'Наличие уточняется',
                    'preorder' => 'Предзаказ',
                    default => 'Нет в наличии',
                } }}
                @if($product->stock_status === 'in_stock')
                    <span>· {{ $product->stock_quantity }} шт.</span>
                @endif
            </p>

            @if($characteristics->isNotEmpty())
                <div class="app-shop__quick-specs">
                    <h2>Основные характеристики</h2>
                    <dl>
                        @foreach($characteristics->take(5) as $value)
                            <div>
                                <dt>{{ $value->attribute?->name }}</dt>
                                <dd>{{ $value->attribute?->type === 'boolean' ? ($value->value === '1' ? 'Да' : 'Нет') : $value->value }} {{ $value->attribute?->unit }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            <div class="app-shop__product-notice">
                Магазин пока работает как каталог. Оформление заказа и оплата на MSKBA недоступны.
            </div>
        </aside>
    </div>

    @if($product->description)
        <section class="app-shop__product-section panel" aria-labelledby="app-shop-desc-title">
            <h2 id="app-shop-desc-title">О товаре</h2>
            <div class="app-shop__description">{{ $product->description }}</div>
        </section>
    @endif

    @if($characteristics->isNotEmpty())
        <section class="app-shop__product-section panel" aria-labelledby="app-shop-specs-title">
            <h2 id="app-shop-specs-title">Характеристики</h2>
            <dl class="app-shop__specs">
                @foreach($characteristics as $value)
                    <div>
                        <dt>{{ $value->attribute?->name }}</dt>
                        <dd>{{ $value->attribute?->type === 'boolean' ? ($value->value === '1' ? 'Да' : 'Нет') : $value->value }} {{ $value->attribute?->unit }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if($product->tags->isNotEmpty())
        <div class="app-shop__product-tags" aria-label="Теги товара">
            @foreach($product->tags as $tag)
                <a class="badge" href="{{ route('shop.index', ['tag' => $tag->slug]) }}">#{{ $tag->name }}</a>
            @endforeach
        </div>
    @endif
</section>
@endsection
