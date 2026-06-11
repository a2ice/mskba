<header class="site-header partial-wrapper partial-header">

    <div class="light-overlay"></div>

    <div class="inner site-header-inner">

        <div class="site-header-left header-cell">
            @include('theme::partials.logo')
        </div>

        <div class="site-header-right header-cell">

            @include('theme::partials.menu.main')

            <div class="site-auth header-cell" aria-label="Пользовательская навигация">

                @include('theme::partials.header-search')

                <div class="header-cell">
                    @include('theme::partials.menu.account-nav')
                </div>

            </div>

        </div>

    </div>

</header>