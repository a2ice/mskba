@php
    $url = route('shop.product', $product->slug);
    $photos = $product->imageUrls();
    $saved = in_array((int) $product->id, $favoriteProductIds ?? [], true);
@endphp
<article class="app-shop__card panel">
    <a href="{{ $url }}" class="app-shop__card-photo" aria-label="{{ $product->name }}">
        @if(count($photos))
            <img src="{{ $photos[0] }}" alt="{{ $product->name }}" loading="lazy">
        @else
            <span class="app-shop__photo-empty" aria-hidden="true">MSKBA</span>
        @endif
    </a>
    <button type="button" class="mskba-favorite-heart mskba-favorite-heart--card"
        data-favorite-type="product" data-favorite-id="{{ $product->id }}"
        data-favorite-name="{{ $product->name }}"
        aria-pressed="{{ $saved ? 'true' : 'false' }}"
        aria-label="{{ $saved ? 'Убрать из избранного' : 'Добавить в избранное' }}"
        title="{{ $saved ? 'Убрать из избранного' : 'Добавить в избранное' }}">
        <svg aria-hidden="true"><use href="#heart"/></svg>
    </button>
    <div class="app-shop__card-body">
        <div class="app-shop__card-meta">
            @if($product->productType)<span class="app-shop__card-badge">{{ $product->productType->name }}</span>@endif
            <span class="app-shop__card-badge">{{ match($product->stock_status) {
                'in_stock' => 'В наличии', 'preorder' => 'Предзаказ',
                'on_request' => 'Наличие уточняется', default => 'Нет в наличии',
            } }}</span>
        </div>
        <h2><a href="{{ $url }}">{{ $product->name }}</a></h2>
        @if($product->sku)<p class="app-shop__card-sku">Артикул: {{ $product->sku }}</p>@endif
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
        <a class="button secondary app-shop__card-details" href="{{ $url }}">Подробнее <span aria-hidden="true">→</span></a>
    </div>
</article>
