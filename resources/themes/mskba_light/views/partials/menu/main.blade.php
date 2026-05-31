@php
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
        ->resolve($page ?? 'main');
@endphp

<div class="partial-wrapper partial-main-menu">
    @if ($menuItems !== [])
        <nav class="site-nav">
            <ul class="main-menu-nav nav">
                @foreach ($menuItems as $item)
                    @continue(! $item['visible'])

                    @if (isset($item['divider']) && $item['divider'])
                        <li class="nav-divider"></li>
                    @endif
                    <li class="nav-item {{ $item['active'] ? 'active' : '' }}">
                        <a href="{{ $item['url'] }}" class="nav-link {{ $item['active'] ? 'active' : '' }}">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</div>