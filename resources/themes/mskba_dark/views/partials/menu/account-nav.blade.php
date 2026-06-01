<div class="partial-wrapper partial-account-nav">
    <div class="account-nav" aria-label="Навигация аккаунта">
        @guest
            <button
                type="button"
                @class(['btn', 'btn--secondary', 'btn--sm', 'site-auth__button', 'js-handler', 'is-active' => request()->routeIs('login', 'register')])
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="auth-entry-classic"
            >
                Войти
            </button>
        @else
            <a href="{{ route('account') }}" @class(['btn', 'btn--secondary', 'btn--sm', 'site-auth__button', 'is-active' => request()->routeIs('account')])>
                {{ $userLoginLabel ?? 'Аккаунт' }}
            </a>
        @endguest
    </div>
</div>