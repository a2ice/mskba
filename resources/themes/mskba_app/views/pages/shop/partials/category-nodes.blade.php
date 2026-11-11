@foreach($nodes as $node)
    @php
        $item = $node['category'];
        $expanded = in_array((int) $item->id, $expandedCategoryIds, true);
        $selected = $category?->id === $item->id;
        $childrenId = 'shop-'.$scope.'-category-'.$item->id;
    @endphp
    <div class="app-shop__category-node">
        <div class="app-shop__category-row">
            <a @class(['is-active' => $selected]) href="{{ route('shop.category', $item->slug) }}"
               @if($selected) aria-current="page" @endif>{{ $item->name }}</a>
            @if(count($node['children']))
                <button class="app-shop__category-toggle" type="button" data-shop-category-toggle
                    aria-label="Подкатегории: {{ $item->name }}"
                    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                    aria-controls="{{ $childrenId }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            @endif
        </div>
        @if(count($node['children']))
            <div class="app-shop__category-children" id="{{ $childrenId }}" @unless($expanded) hidden @endunless>
                @include('theme::pages.shop.partials.category-nodes', [
                    'nodes' => $node['children'],
                    'scope' => $scope,
                ])
            </div>
        @endif
    </div>
@endforeach
