@php
    $formId = $formId ?? 'shop-catalog-filter-form';
    $action = $category ? route('shop.category', $category->slug) : route('shop.index');
@endphp
<form id="{{ $formId }}" method="GET" action="{{ $action }}"
      class="default-category-filter shop-catalog__filter" data-default-category-filter-form>
    <input type="hidden" name="q" value="{{ $filters['q'] }}">
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}"
           data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Тип товара</span>
        <select name="product_type" class="form-select">
            <option value="">Все типы</option>
            @foreach($types as $type)
                <option value="{{ $type->id }}" @selected($filters['product_type'] === $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="default-category-filter__field">
        <span>Наличие</span>
        <select name="stock" class="form-select">
            <option value="">Любое</option>
            <option value="in_stock" @selected($filters['stock'] === 'in_stock')>В наличии</option>
            <option value="on_request" @selected($filters['stock'] === 'on_request')>Наличие уточняется</option>
            <option value="preorder" @selected($filters['stock'] === 'preorder')>Предзаказ</option>
            <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Нет в наличии</option>
        </select>
    </label>
    <div class="default-category-filter__field">
        <span>Цена, ₽</span>
        <div class="shop-catalog__price-range">
            <label><span class="visually-hidden">Цена от</span><input class="form-control" type="number" name="min_price" min="0" step="1" placeholder="От" value="{{ $filters['min_price'] }}"></label>
            <label><span class="visually-hidden">Цена до</span><input class="form-control" type="number" name="max_price" min="0" step="1" placeholder="До" value="{{ $filters['max_price'] }}"></label>
        </div>
    </div>
    @foreach(['color' => 'Цвет', 'size' => 'Размер'] as $code => $label)
        @if(count($availableAttributes[$code]))
            <label class="default-category-filter__field">
                <span>{{ $label }}</span>
                <select name="{{ $code }}" class="form-select">
                    <option value="">Все варианты</option>
                    @foreach($availableAttributes[$code] as $value)
                        <option value="{{ $value }}" @selected($filters[$code] === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    @endforeach
    @if($tags->isNotEmpty())
        <label class="default-category-filter__field">
            <span>Теги</span>
            <select name="tag" class="form-select">
                <option value="">Все теги</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->slug }}" @selected($filters['tag'] === $tag->slug)>{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="default-category-filter__field">
        <span>Сортировать</span>
        <select name="sort" class="form-select">
            <option value="">По порядку</option>
            <option value="new" @selected($filters['sort'] === 'new')>Сначала новые</option>
            <option value="price_asc" @selected($filters['sort'] === 'price_asc')>Сначала дешевле</option>
            <option value="price_desc" @selected($filters['sort'] === 'price_desc')>Сначала дороже</option>
        </select>
    </label>
    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ $action }}">Сбросить</a>
    </div>
</form>
