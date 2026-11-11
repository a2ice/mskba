@php($filterAction = $category ? route('shop.category', $category->slug) : route('shop.index'))
<form id="{{ $formId ?? 'shop-filter-form' }}" method="GET" action="{{ $filterAction }}" class="app-shop__filters">
    @if($filters['view'] === 'list')<input type="hidden" name="view" value="list">@endif
    @if($filters['sort'] !== '')<input type="hidden" name="sort" value="{{ $filters['sort'] }}">@endif
    <label><span>Поиск товара</span>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название товара или артикул">
    </label>

    <label><span>Тип товара</span>
        <span class="select-control"><select name="product_type">
            <option value="">Все типы</option>
            @foreach($types as $type)
                <option value="{{ $type->id }}" @selected($filters['product_type'] === $type->id)>{{ $type->name }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>

    <label><span>Наличие</span>
        <span class="select-control"><select name="stock">
            <option value="">Любое</option>
            <option value="in_stock" @selected($filters['stock'] === 'in_stock')>В наличии</option>
            <option value="on_request" @selected($filters['stock'] === 'on_request')>Уточняется</option>
            <option value="preorder" @selected($filters['stock'] === 'preorder')>Предзаказ</option>
            <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Нет в наличии</option>
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>

    <fieldset>
        <legend>Цена, ₽</legend>
        <div class="app-shop__price-range" data-shop-price-range data-price-floor="0" data-price-ceiling="{{ $priceCeiling }}">
            <div class="app-shop__price-range-labels">
                <output data-shop-price-label="min">{{ number_format($filters['min_price'] ?? 0, 0, ',', ' ') }} ₽</output>
                <output data-shop-price-label="max">{{ number_format($filters['max_price'] ?? $priceCeiling, 0, ',', ' ') }} ₽</output>
            </div>
            <div class="app-shop__price-range-track">
                <input type="range" min="0" max="{{ $priceCeiling }}" step="1"
                    value="{{ $filters['min_price'] ?? 0 }}" aria-label="Цена от" data-shop-price-min>
                <input type="range" min="0" max="{{ $priceCeiling }}" step="1"
                    value="{{ $filters['max_price'] ?? $priceCeiling }}" aria-label="Цена до" data-shop-price-max>
            </div>
            <input type="hidden" name="min_price" value="{{ $filters['min_price'] ?? '' }}" data-shop-price-min-value>
            <input type="hidden" name="max_price" value="{{ $filters['max_price'] ?? '' }}" data-shop-price-max-value>
        </div>
    </fieldset>

    @foreach(['color' => 'Цвет', 'size' => 'Размер'] as $code => $label)
        @if(count($availableAttributes[$code]))
            <label><span>{{ $label }}</span>
                <span class="select-control"><select name="{{ $code }}">
                    <option value="">Все</option>
                    @foreach($availableAttributes[$code] as $value)
                        <option value="{{ $value }}" @selected($filters[$code] === $value)>{{ $value }}</option>
                    @endforeach
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
            </label>
        @endif
    @endforeach

    @if($tags->isNotEmpty())
        <label><span>Теги</span>
            <span class="select-control"><select name="tag">
                <option value="">Все теги</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->slug }}" @selected($filters['tag'] === $tag->slug)>{{ $tag->name }}</option>
                @endforeach
            </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
        </label>
    @endif


    <div class="app-shop__filter-actions">
        <button type="submit" class="button primary">Применить</button>
    </div>
</form>
