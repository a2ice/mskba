@php
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
        ->resolve($menu ?? 'main');
@endphp

@if ($menuItems !== [])
    <nav class="mskba-nav" id="mskba-main-nav" aria-label="Главная навигация">
        @foreach ($menuItems as $item)
            @continue(! $item['visible'])

            @php
                $children = $item['children'] ?? [];
                $visibleChildren = array_values(array_filter($children, fn (array $child): bool => $child['visible']));
            @endphp

            @if ($visibleChildren !== [])
                <div @class(['mskba-nav__item', 'mskba-nav__item--dropdown', 'is-active' => $item['active']])>
                    <button class="mskba-nav__link mskba-nav__toggle" type="button" aria-haspopup="true" aria-expanded="false">
                        {{ $item['label'] }}
                    </button>

                    <div class="mskba-nav__dropdown" role="menu">
                        @foreach ($visibleChildren as $child)
                            @if (! empty($child['divider']))
                                <span class="mskba-nav__divider"></span>
                            @endif

                            <a href="{{ $child['url'] }}" @class(['mskba-nav__dropdown-link', 'is-active' => $child['active']]) role="menuitem">
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ $item['url'] }}" @class(['mskba-nav__link', 'is-active' => $item['active']])>
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach

        <div class="mskba-nav__mobile-lang">
            @include('theme::partials.lang-switcher')
        </div>
    </nav>
@endif
