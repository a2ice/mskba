@php
    $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
        ->resolve($menu ?? 'main');
@endphp

<div class="partial-wrapper partial-main-menu site-header-nav-wrapper header-cell">
    <div class="nav-hamburger js-handler" data-handler="toggleClass" data-params="nav-shown;body" data-nav-toggle aria-expanded="false" aria-label="Открыть основное меню">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <div class="site-nav-wrapper">
        <div class="mobile-nav-accordion" data-mobile-nav-accordion>
            <section class="mobile-nav-section mobile-nav-section--main" data-mobile-nav-section="main">
                <button
                    class="mobile-nav-section__toggle"
                    type="button"
                    aria-expanded="true"
                    aria-controls="mobileMainNavigation"
                    data-mobile-nav-section-toggle="main"
                >
                    <span>Главное меню</span>
                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                </button>

                <div id="mobileMainNavigation" class="mobile-nav-section__panel" data-mobile-nav-section-panel="main">
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
                                                @if (!empty($child['divider']))
                                                    <span class="nav-divider"></span>
                                                @endif

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

                    <div class="site-nav__mobile-auth" aria-label="Навигация аккаунта">
                        @guest
                            <button
                                type="button"
                                class="site-nav__link site-nav__mobile-account-link js-handler"
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="auth-entry-classic"
                            >
                                Личный кабинет
                            </button>
                        @else
                            <a href="{{ route('account') }}" class="site-nav__link site-nav__mobile-account-link">
                                Личный кабинет
                            </a>
                        @endguest
                    </div>
                </div>
            </section>

            <section class="mobile-nav-section mobile-nav-section--sidebar" data-mobile-nav-section="sidebar" hidden>
                <button
                    class="mobile-nav-section__toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="mobileSectionNavigation"
                    data-mobile-nav-section-toggle="sidebar"
                >
                    <span data-mobile-nav-sidebar-title>Навигация раздела</span>
                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                </button>

                <div
                    id="mobileSectionNavigation"
                    class="mobile-nav-section__panel mobile-nav-sidebar-slot"
                    data-mobile-nav-section-panel="sidebar"
                    data-mobile-nav-sidebar-slot
                    hidden
                ></div>
            </section>
        </div>
    </div>
</div>
