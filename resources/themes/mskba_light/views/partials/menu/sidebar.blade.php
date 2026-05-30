@php
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
        ->resolve($page ?? 'default');
@endphp

<div class="partial-wrapper partial-sidebar-menu">
    @if ($menuItems !== [])
        <ul class="sidebar-nav nav flex-column">
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
    @endif
</div>