<nav class="app-shop__categories" aria-label="Категории магазина">
    <a @class(['is-active' => ! $category]) href="{{ route('shop.index') }}" @if(! $category) aria-current="page" @endif>
        <span>Все товары</span><span aria-hidden="true">›</span>
    </a>
    @foreach($categoryNav as $node)
        @php($item = $node['category'])
        <a @class(['is-active' => $category?->id === $item->id])
           style="--depth: {{ min((int) $node['depth'], 6) }}"
           href="{{ route('shop.category', $item->slug) }}"
           @if($category?->id === $item->id) aria-current="page" @endif>
            <span>{{ $item->name }}</span><span aria-hidden="true">›</span>
        </a>
    @endforeach
</nav>
