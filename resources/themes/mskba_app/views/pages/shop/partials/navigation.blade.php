<nav class="app-shop__categories" aria-label="Категории магазина">
    <a @class(['is-active' => ! $category]) href="{{ route('shop.index') }}" @if(! $category) aria-current="page" @endif>
        <span>Все товары</span>
    </a>
    @include('theme::pages.shop.partials.category-nodes', ['nodes' => $categoryTree, 'scope' => $scope ?? 'desktop'])
</nav>
