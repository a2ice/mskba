<header class="mskba-header" data-mskba-header>
    @include('theme::partials.logo')

    <span class="mskba-header__divider" aria-hidden="true"></span>

    @include('theme::partials.lang-switcher')

    @include('theme::partials.menu.main')

    @include('theme::partials.header-actions')

    <button class="mskba-burger" type="button" aria-label="Открыть меню" aria-expanded="false" aria-controls="mskba-main-nav" data-mskba-menu-toggle>
        <span></span>
        <span></span>
        <span></span>
    </button>
</header>
