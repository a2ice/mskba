@if(trim((string) config('vk.app_id')) !== '')
    <div class="auth-vk-login{{ ($authSocialGrid ?? false) ? ' auth-social-login__provider' : '' }}">
        @unless($authSocialGrid ?? false)
            <div class="auth-telegram-login__separator" aria-hidden="true">
                <span>или</span>
            </div>
        @endunless

        <a
            class="auth-social-login__button auth-social-login__button--vk"
            href="{{ route('auth.vk.start', ! empty($authRedirectTo) ? ['redirect_to' => $authRedirectTo] : []) }}"
            data-vk-auth-url="{{ route('auth.vk.start', [], false) }}"
        >
            <span class="auth-social-login__icon auth-vk-login__icon" aria-hidden="true">VK</span>
            <span>VKontakte</span>
        </a>
    </div>
@endif
