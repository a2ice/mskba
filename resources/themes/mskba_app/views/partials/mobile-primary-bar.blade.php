@php
    $accountContext = request()->is('account', 'account/*');
    $siteItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('main');
    if ($accountContext) {
        $accountItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('account');
    }
@endphp

{{-- The production mobile dock belongs to the current theme, not the legacy skin. --}}
<div class="app-mobile-primary-backdrop" data-app-mobile-primary-backdrop hidden></div>
<div class="app-mobile-primary" data-app-mobile-primary>
    <section class="app-mobile-primary__panel" id="app-mobile-primary-menu"
             data-app-mobile-primary-panel role="dialog" aria-modal="true"
             aria-labelledby="app-mobile-primary-title" hidden>
        <header class="app-mobile-primary__panel-header">
            <h2 id="app-mobile-primary-title">{{ $accountContext ? 'Разделы аккаунта' : 'Меню' }}</h2>
            <button type="button" class="app-mobile-primary__close" data-app-mobile-primary-close
                    aria-label="Закрыть меню">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>

        @if ($accountContext)
            <nav class="app-mobile-primary__account-nav app-account-nav__links" aria-label="Разделы аккаунта">
                @include('theme::partials.account.nav-items', [
                    'accountItems' => $accountItems,
                    'accountNavDepth' => 0,
                ])
            </nav>
        @endif

        <details class="app-mobile-primary__site-section" @unless ($accountContext) open @endunless>
            <summary class="app-mobile-primary__site-trigger">
                Разделы сайта
                <svg aria-hidden="true"><use href="#chevron-down"/></svg>
            </summary>
            <nav class="app-mobile-primary__site-links" aria-label="Разделы сайта">
                @foreach ($siteItems as $item)
                    @continue(! ($item['visible'] ?? true))
                    @php
                        $children = array_values(array_filter(
                            $item['children'] ?? [],
                            fn (array $child): bool => $child['visible'] ?? false
                        ));
                    @endphp
                    @if ($children !== [])
                        <details class="app-mobile-primary__site-group">
                            <summary class="app-mobile-primary__site-link">
                                {{ $item['label'] }}
                                <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                            </summary>
                            <div class="app-mobile-primary__site-children">
                                @if (! empty($item['url']))
                                    <a href="{{ $item['url'] }}">Все {{ mb_strtolower($item['label']) }}</a>
                                @endif
                                @foreach ($children as $child)
                                    <a href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                    @elseif (! empty($item['url']))
                        <a class="app-mobile-primary__site-link" href="{{ $item['url'] }}"
                           @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endif
                @endforeach
                @unless ($accountContext)
                    @auth
                        <a class="app-mobile-primary__site-link" href="{{ route('account') }}">Личный кабинет</a>
                        <a class="app-mobile-primary__site-link" href="{{ route('account.notifications') }}">Сообщения</a>
                    @else
                        <a class="app-mobile-primary__site-link" href="{{ route('login') }}" data-auth-trigger>Войти</a>
                    @endauth
                @endunless
            </nav>
        </details>
    </section>

    <nav class="app-mobile-primary__bar" aria-label="Основная мобильная навигация">
        <a class="app-mobile-primary__action"
           href="{{ route('events.index', ['type' => 'games']) }}"
           @if (request()->routeIs('events.index') && request()->query('type') === 'games') aria-current="page" @endif>
            <svg aria-hidden="true"><use href="#ball"/></svg>
            <span>Играть</span>
        </a>
        <a class="app-mobile-primary__action"
           href="{{ route('venues') }}"
           @if (request()->routeIs('venues', 'venues.*')) aria-current="page" @endif>
            <svg aria-hidden="true"><use href="#pin"/></svg>
            <span>Площадки</span>
        </a>
        <a class="app-mobile-primary__action app-mobile-primary__action--home"
           href="{{ route('welcome') }}" aria-label="На главную"
           @if (request()->routeIs('welcome')) aria-current="page" @endif>
            <span class="app-mobile-primary__home-mark" aria-hidden="true"></span>
        </a>
        <a class="app-mobile-primary__action"
           href="{{ route('events.index', ['type' => 'training']) }}"
           @if (request()->routeIs('events.index') && request()->query('type') === 'training') aria-current="page" @endif>
            <svg aria-hidden="true"><use href="#barbell"/></svg>
            <span>Тренировки</span>
        </a>
        <button class="app-mobile-primary__action app-mobile-primary__action--menu"
                type="button" data-app-mobile-primary-toggle
                aria-controls="app-mobile-primary-menu" aria-expanded="false"
                aria-label="Открыть меню">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="app-mobile-primary__menu-lines">
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <span>Меню</span>
        </button>
    </nav>
</div>
