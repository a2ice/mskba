@php($filterAction = $category ? route('shop.category', $category->slug) : route('shop.index'))
<form id="{{ $formId ?? 'shop-filter-form' }}" method="GET" action="{{ $filterAction }}" class="app-shop__filters">
    @if($filters['view'] === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Поиск товара</span>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название товара или артикул">
    </label>

    <label><span>Тип товара</span>
        <select name="product_type">
            <option value="">Все типы</option>
            @foreach($types as $type)
                <option value="{{ $type->id }}" @selected($filters['product_type'] === $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </label>

    <label><span>Наличие</span>
        <select name="stock">
            <option value="">Любое</option>
            <option value="in_stock" @selected($filters['stock'] === 'in_stock')>В наличии</option>
            <option value="on_request" @selected($filters['stock'] === 'on_request')>Уточняется</option>
            <option value="preorder" @selected($filters['stock'] === 'preorder')>Предзаказ</option>
            <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Нет в наличии</option>
        </select>
    </label>

    <fieldset>
        <legend>Цена, ₽</legend>
        <div class="app-shop__price-fields">
            <label><span class="sr-only">Цена от</span><input name="min_price" type="number" min="0" step="1" value="{{ $filters['min_price'] }}" placeholder="От"></label>
            <label><span class="sr-only">Цена до</span><input name="max_price" type="number" min="0" step="1" value="{{ $filters['max_price'] }}" placeholder="До"></label>
        </div>
    </fieldset>

    @foreach(['color' => 'Цвет', 'size' => 'Размер'] as $code => $label)
        @if(count($availableAttributes[$code]))
            <label><span>{{ $label }}</span>
                <select name="{{ $code }}">
                    <option value="">Все</option>
                    @foreach($availableAttributes[$code] as $value)
                        <option value="{{ $value }}" @selected($filters[$code] === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    @endforeach

    @if($tags->isNotEmpty())
        <label><span>Теги</span>
            <select name="tag">
                <option value="">Все теги</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->slug }}" @selected($filters['tag'] === $tag->slug)>{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <label><span>Сортировка</span>
        <select name="sort">
            <option value="">По порядку</option>
            <option value="new" @selected($filters['sort'] === 'new')>Сначала новые</option>
            <option value="price_asc" @selected($filters['sort'] === 'price_asc')>Сначала дешевле</option>
            <option value="price_desc" @selected($filters['sort'] === 'price_desc')>Сначала дороже</option>
        </select>
    </label>

    <div class="app-shop__filter-actions">
        <button type="submit" class="button primary">Применить</button>
        <a href="{{ $filterAction }}" class="button secondary">Сбросить</a>
    </div>
</form>
