@if(trim((string) config('vk.app_id')) !== '')
    <div class="auth-vk-login">
        <div class="auth-telegram-login__separator" aria-hidden="true">
            <span>или через VK ID</span>
        </div>

        <a
            class="btn btn--sm auth-vk-login__button"
            href="{{ route('auth.vk.start', ['redirect_to' => request()->fullUrl()]) }}"
            data-vk-auth-url="{{ route('auth.vk.start', [], false) }}"
        >
            <span class="auth-vk-login__icon" aria-hidden="true">VK</span>
            Войти через VK ID
        </a>
    </div>
@endif
