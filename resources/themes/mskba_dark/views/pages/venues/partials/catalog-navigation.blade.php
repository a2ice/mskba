<nav class="default-category-sidebar__nav" aria-label="Навигация по площадкам">
    <a class="is-active" href="{{ route('venues') }}" aria-current="page">
        <span>Все площадки</span><i class="ti ti-map-pin" aria-hidden="true"></i>
    </a>

    @auth
        <a href="{{ route('account.venues') }}">
            <span>Мои площадки</span><i class="ti ti-building-community" aria-hidden="true"></i>
        </a>
        <a href="{{ route('venues.create') }}">
            <span>Добавить площадку</span><i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @else
        <button
            type="button"
            class="js-handler"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="auth-entry-classic"
            data-auth-redirect-url="{{ route('venues.create', [], false) }}"
        >
            <span>Добавить площадку</span><i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
</nav>
