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
    <section class="app-mobile-primary__panel app-mobile-primary__panel--links" id="app-mobile-primary-play"
             data-app-mobile-primary-play-panel role="dialog" aria-modal="true"
             aria-labelledby="app-mobile-primary-play-title" hidden>
        <header class="app-mobile-primary__panel-header">
            <h2 id="app-mobile-primary-play-title">Играть</h2>
            <button type="button" class="app-mobile-primary__close" data-app-mobile-primary-close aria-label="Закрыть">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>
        <nav class="app-mobile-primary__site-links" aria-label="Игры и соревнования">
            <a class="app-mobile-primary__site-link" href="{{ route('events.index', ['type' => 'game']) }}">Игры</a>
            <a class="app-mobile-primary__site-link" href="{{ route('tournaments.index') }}">Турниры</a>
            <a class="app-mobile-primary__site-link" href="{{ route('leagues.index') }}">Лиги</a>
            @if(config('features.sports_sections.enabled'))
                <a class="app-mobile-primary__site-link" href="{{ route('sports-sections.index') }}">Секции</a>
            @endif
        </nav>
    </section>

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

    <section class="app-mobile-primary__panel app-mobile-primary__panel--filters" id="app-mobile-primary-filters"
             data-app-mobile-primary-filter-panel role="dialog" aria-modal="true"
             aria-labelledby="app-mobile-primary-filter-title" hidden>
        <header class="app-mobile-primary__panel-header">
            <h2 id="app-mobile-primary-filter-title" data-app-mobile-primary-filter-title>Поиск</h2>
            <button type="button" class="app-mobile-primary__close" data-app-mobile-primary-close aria-label="Закрыть">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>
        <div class="app-mobile-primary__filter-preview" data-context-filter-preview>
            <label class="app-mobile-primary__filter-preview-label" for="app-mobile-primary-quick-search">Поиск</label>
            <input type="search" id="app-mobile-primary-quick-search" data-context-filter-quick-search
                   autocomplete="off" placeholder="Что хотите найти?">
        </div>
        <div class="app-mobile-primary__filter-scroll" id="app-mobile-primary-filter-scroll"
             data-context-filter-scroll>
            <div data-context-filter-host></div>
            <form method="GET" action="{{ url('/search') }}" data-context-global-search>
                <label for="app-mobile-primary-global-query">Поиск по всему сайту</label>
                <input id="app-mobile-primary-global-query" type="search" name="q" maxlength="100"
                       value="{{ request()->routeIs('site.search') ? request('q', '') : '' }}"
                       placeholder="Площадки, команды, турниры…">
                <fieldset>
                    <legend>Где искать</legend>
                    @foreach ([
                        'venues' => 'Площадки', 'events' => 'Мероприятия',
                        'teams' => 'Команды', 'people' => 'Участники',
                        'tournaments' => 'Турниры', 'leagues' => 'Лиги',
                        'sections' => 'Секции', 'news' => 'Новости', 'shop' => 'Магазин',
                        'faq' => 'Помощь и FAQ',
                    ] as $searchType => $searchLabel)
                        <label class="app-mobile-primary__search-type">
                            <input type="checkbox" name="types[]" value="{{ $searchType }}"
                                   @checked(! request()->routeIs('site.search') || ! request()->has('types') || in_array($searchType, (array) request('types', []), true))>
                            <span>{{ $searchLabel }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </form>
        </div>
        <div class="app-mobile-primary__result-strip" data-context-result-strip role="status" aria-live="polite">
            <span>Найдено:</span>
            <strong data-context-filter-result-value>—</strong>
            <button type="button" class="app-mobile-primary__filter-expand" data-context-filter-expand
                    aria-expanded="false" aria-controls="app-mobile-primary-filter-scroll"
                    aria-label="Развернуть фильтры" title="Развернуть фильтры">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="app-mobile-primary__filter-expand-icon">
                    <path d="M4 7h16M7 12h10M10 17h4"/>
                </svg>
                <svg aria-hidden="true" class="app-mobile-primary__filter-chevron"><use href="#chevron-down"/></svg>
            </button>
        </div>
        <footer class="app-mobile-primary__filter-footer">
            <button type="button" class="button secondary" data-context-filter-reset>Сбросить</button>
            <button type="button" class="button primary" data-context-filter-apply>Применить</button>
        </footer>
    </section>

    <nav class="app-mobile-primary__bar" aria-label="Основная мобильная навигация">
        <button class="app-mobile-primary__action app-mobile-primary__action--play"
                type="button" data-app-mobile-primary-play-toggle
                aria-controls="app-mobile-primary-play" aria-expanded="false"
                aria-label="Открыть раздел Играть">
            <svg aria-hidden="true"><use href="#ball"/></svg>
            <span>Играть</span>
        </button>
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

        <button class="app-mobile-primary__action app-mobile-primary__action--menu"
                type="button" data-app-mobile-primary-toggle
                aria-controls="app-mobile-primary-menu" aria-expanded="false"
                aria-label="Открыть меню">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="app-mobile-primary__menu-lines">
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <span>Меню</span>
        </button>
        <button class="app-mobile-primary__action app-mobile-primary__action--filter"
                type="button" data-app-mobile-primary-filter-toggle
                aria-controls="app-mobile-primary-filters" aria-expanded="false"
                aria-label="Открыть поиск">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="app-mobile-primary__search-icon">
                <circle cx="10.8" cy="10.8" r="6.7"/>
                <path d="m16 16 5 5"/>
            </svg>
            <svg aria-hidden="true" viewBox="0 0 24 24" class="app-mobile-primary__catalog-filter-icon">
                <path d="M4 7h16M7 12h10M10 17h4"/>
            </svg>
            <span data-app-mobile-primary-filter-label>Поиск</span>
            <span class="app-mobile-primary__filter-badge" data-app-mobile-primary-filter-badge hidden></span>
        </button>
    </nav>
</div>
