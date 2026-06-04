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
        @else
            @php
                $headerNewNotificationsCount = app(\App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler::class)
                    ->handle(auth()->user());
            @endphp
            <a href="{{ route('account') }}" @class(['btn', 'btn--secondary', 'btn--sm', 'site-auth__button', 'is-active' => request()->routeIs('account')])>
                {{ $userLoginLabel ?? 'Аккаунт' }}
                @if($headerNewNotificationsCount > 0)
                    <span class="site-auth__notification-badge" data-notification-count aria-label="Новые уведомления: {{ $headerNewNotificationsCount }}">
                        {{ $headerNewNotificationsCount > 9 ? '...' : $headerNewNotificationsCount }}
                    </span>
                @endif
            </a>
        @endguest
    </div>
</div>
