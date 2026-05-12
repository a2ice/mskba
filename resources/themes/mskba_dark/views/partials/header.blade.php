@php
    use Illuminate\Support\Str;

    $navItems = [
        ['label' => 'Площадки', 'href' => '#tournaments', 'active' => request()->routeIs('venues.index')],
        ['label' => 'Игры', 'href' => '#tournaments', 'active' => request()->routeIs('events.index')],
        ['label' => 'Турниры', 'href' => '#tournaments', 'active' => false],
        ['label' => 'Новости', 'href' => '#news', 'active' => false],
        ['label' => 'Контакты', 'href' => '#contscts', 'active' => false],
    ];
    $user = auth()->user();
    $userLogin = $user?->login === null ? null : (string) $user->login;
    $userLoginLabel = $userLogin !== null && Str::length($userLogin) > 15
        ? Str::substr($userLogin, 0, 12).'...'
        : $userLogin;
@endphp

<header class="site-header">
    <div class="site-header-left header-cell">
        <a class="brand" href="{{ route('home') }}" aria-label="MSKBA">
            <span class="brand__text">
                <span class="brand__name">MSK<span>BA</span></span>
                <span class="brand__descriptor"><span class="brand__descriptor-row">баскетбольный портал</span><br> <span class="brand__descriptor-row">Москвы и области</span></span>
            </span>
        </a>
    </div>
        
    <div class="site-header-right header-cell">

        <div class="site-header-nav-wrapper header-cell">
            <div class="nav-hamburger js-handler" data-handler="toggleClass" data-params="nav-shown;body">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <div class="site-nav-wrapper">
                <nav class="site-nav" aria-label="Основная навигация">
                    @foreach ($navItems as $item)
                        <a href="{{ $item['href'] }}" @class(['site-nav__link', 'is-active' => $item['active']])>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            </div>
        </div>

        <div class="site-auth header-cell" aria-label="Пользовательская навигация">
            <div class="header-cell header-search-wrapper">
                <button class="site-search" type="button" aria-label="Поиск">
                    <span></span>
                </button>
            </div>

            <div class="header-cell">
                @guest
                    <button
                        type="button"
                        @class(['btn', 'btn--primary', 'btn--sm', 'js-handler', 'is-active' => request()->routeIs('login', 'register')])
                        data-handler="modal"
                        data-modal-action="open"
                        data-modal-target="auth-entry"
                    >
                        Войти
                    </button>
                @else
                    <a href="{{ route('account') }}" @class(['btn', 'btn--secondary', 'btn--sm', 'is-active' => request()->routeIs('account')])>
                        {{ $userLoginLabel ?? 'Аккаунт' }}
                    </a>
                @endguest
            </div>
        </div>

    </div>

</header>
