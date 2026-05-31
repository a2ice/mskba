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
                    <a href="{{ $item['url'] }}" class="nav-link {{ $item['active'] ? 'active' : '' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
</div>