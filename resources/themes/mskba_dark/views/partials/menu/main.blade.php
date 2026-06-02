@php
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
        ->resolve($page ?? 'main');
@endphp

<div class="partial-wrapper partial-main-menu site-header-nav-wrapper header-cell">
    <div class="nav-hamburger js-handler" data-handler="toggleClass" data-params="nav-shown;body">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <div class="site-nav-wrapper">
        @if ($menuItems !== [])
            <nav class="site-nav" aria-label="Основная навигация">
                @foreach ($menuItems as $item)
                    @continue(! $item['visible'])
                    @php
                        $children = $item['children'] ?? [];
                        $visibleChildren = array_values(array_filter($children, fn (array $child): bool => $child['visible']));
                    @endphp

                    @if ($visibleChildren !== [])
                        <div @class(['site-nav__item', 'site-nav__item--dropdown', 'is-active' => $item['active']])>
                            <button class="site-nav__link site-nav__toggle" type="button" aria-haspopup="true" aria-expanded="false">
                                {{ $item['label'] }}
                            </button>

                            <div class="site-nav__dropdown" role="menu">
                                @foreach ($visibleChildren as $child)
                                    <a
                                        href="{{ $child['url'] }}"
                                        @class(['site-nav__dropdown-link', 'is-active' => $child['active']])
                                        role="menuitem"
                                    >
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <a href="{{ $item['url'] }}" @class(['site-nav__link', 'is-active' => $item['active']])>
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            </nav>
        @endif
    </div>
</div>
