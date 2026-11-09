<nav class="default-category-sidebar__nav shop-catalog__nav" aria-label="Категории магазина">
    <a @class(['is-active' => ! $category]) href="{{ route('shop.index') }}" @if(! $category) aria-current="page" @endif>
        <span>Все товары</span><i class="ti ti-shopping-bag" aria-hidden="true"></i>
    </a>
    @foreach($categoryNav as $node)
        @php($item = $node['category'])
        <a @class(['is-active' => $category?->id === $item->id, 'shop-catalog__nav-child' => $node['depth'] > 0])
           href="{{ route('shop.category', $item->slug) }}"
           style="--shop-depth: {{ min($node['depth'], 6) }}"
           @if($category?->id === $item->id) aria-current="page" @endif>
            <span>{{ $item->name }}</span>
            <i class="ti {{ $node['depth'] > 0 ? 'ti-chevron-right' : 'ti-folder' }}" aria-hidden="true"></i>
        </a>
    @endforeach
</nav>
