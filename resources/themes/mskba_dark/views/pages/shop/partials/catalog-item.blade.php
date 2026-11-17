@php
    $mode = $mode ?? 'card';
    $photos = $product->imageUrls();
    $url = route('shop.product', $product->slug);
@endphp
<article class="shop-catalog-item shop-catalog-item--{{ $mode }}">
    <a class="shop-catalog-item__media" href="{{ $url }}" aria-label="{{ $product->name }}">
        @if(count($photos))
            <img src="{{ $photos[0] }}" alt="{{ $product->name }}" loading="lazy">
        @else
            <span class="shop-image-placeholder"><i class="ti ti-shopping-bag" aria-hidden="true"></i></span>
        @endif
    </a>
    <div class="shop-catalog-item__body">
        <div class="shop-catalog-item__meta">
            @if($product->productType)
                <span>{{ $product->productType->name }}</span>
            @elseif($product->categories->isNotEmpty())
                <span>{{ $product->categories->first()->name }}</span>
            @endif
            @if($product->sku)
                <span>Арт. {{ $product->sku }}</span>
            @endif
        </div>
        <h2 class="shop-catalog-item__title"><a href="{{ $url }}">{{ $product->name }}</a></h2>
        <div class="shop-catalog-item__price">
            @if($product->price_kopecks !== null)
                <strong>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->price_kopecks) }} ₽</strong>
                @if($product->old_price_kopecks > $product->price_kopecks)
                    <del>{{ \App\Modules\Shop\Domain\Models\ShopProduct::rubles($product->old_price_kopecks) }} ₽</del>
                @endif
            @else
                <span>Цена по запросу</span>
            @endif
        </div>
        <div class="shop-catalog-item__footer">
            <span @class(['shop-stock', 'is-available' => $product->stock_status === 'in_stock'])>
                {{ match($product->stock_status) {
                    'in_stock' => 'В наличии',
                    'preorder' => 'Предзаказ',
                    'on_request' => 'Наличие уточняется',
                    default => 'Нет в наличии',
                } }}
            </span>
            <a class="btn btn--secondary btn--sm" href="{{ $url }}">
                Подробнее <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</article>
