@php
    $url = route('shop.product', $product->slug);
    $photos = $product->imageUrls();
@endphp
<article class="app-shop__card panel">
    <a href="{{ $url }}" class="app-shop__card-photo" aria-label="{{ $product->name }}">
        @if(count($photos))
            <img src="{{ $photos[0] }}" alt="{{ $product->name }}" loading="lazy">
        @else
            <span class="app-shop__photo-empty" aria-hidden="true">MSKBA</span>
        @endif
    </a>
    <div class="app-shop__card-body">
        <div class="app-shop__card-meta">
            @if($product->productType)<span>{{ $product->productType->name }}</span>@endif
            @if($product->sku)<span>Арт. {{ $product->sku }}</span>@endif
        </div>
        <h2><a href="{{ $url }}">{{ $product->name }}</a></h2>
        <div class="app-shop__card-price">
            @if($product->price_kopecks !== null)
                <strong>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->price_kopecks) }} ₽</strong>
                @if($product->old_price_kopecks > $product->price_kopecks)
                    <del>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->old_price_kopecks) }} ₽</del>
                @endif
            @else
                <span>Цена по запросу</span>
            @endif
        </div>
        <div class="app-shop__card-footer">
            <span @class(['app-shop__stock', 'is-available' => $product->stock_status === 'in_stock'])>
                {{ match($product->stock_status) {
                    'in_stock' => 'В наличии',
                    'preorder' => 'Предзаказ',
                    'on_request' => 'Наличие уточняется',
                    default => 'Нет в наличии',
                } }}
            </span>
            <a class="button secondary" href="{{ $url }}">Подробнее</a>
        </div>
    </div>
</article>
