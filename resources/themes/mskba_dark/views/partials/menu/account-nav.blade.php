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
            {{-- Регистрация доступна в окне входа.
            <button
                type="button"
                @class(['btn', 'btn--primary', 'btn--sm', 'site-auth__button', 'site-auth__button--register', 'js-handler', 'is-active' => request()->routeIs('register')])
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="auth-entry-classic"
                data-modal-section="register"
            >
                Регистрация
            </button>
            --}}
        @else
            @php
                $headerNewNotificationsCount = app(\App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler::class)
                    ->handle(auth()->user());
            @endphp
            <a href="{{ route('account') }}" aria-label="{{ $userLoginLabel ?? 'Аккаунт' }}" @class(['btn', 'btn--secondary', 'btn--sm', 'site-auth__button', 'is-active' => request()->routeIs('account')])>
                <span class="site-auth__label">{{ $userLoginLabel ?? 'Аккаунт' }}</span>
                <span
                    class="site-auth__notification-badge{{ $headerNewNotificationsCount > 0 ? '' : ' d-none' }}"
                    data-notification-count
                    aria-label="Новые уведомления: {{ $headerNewNotificationsCount }}"
                >{{ $headerNewNotificationsCount > 9 ? '...' : $headerNewNotificationsCount }}</span>
            </a>
        @endguest
    </div>
</div>
