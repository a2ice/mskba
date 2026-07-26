@php
    $telegramBotUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
@endphp

@if($telegramBotUsername !== '')
    <div
        class="auth-telegram-login"
        data-telegram-login
        data-telegram-login-url="{{ route('auth.telegram', [], false) }}"
    >
        <div class="auth-telegram-login__separator" aria-hidden="true">
            <span>или</span>
        </div>

        <p class="auth-telegram-login__title">Войти через Telegram</p>
        <div class="auth-telegram-login__widget">
            <script
                async
                src="https://telegram.org/js/telegram-widget.js?22"
                data-telegram-login="{{ $telegramBotUsername }}"
                data-size="large"
                data-radius="10"
                data-userpic="false"
                data-onauth="mskbaTelegramLogin(user)"
            ></script>
        </div>
        <p class="auth-telegram-login__message form-message" aria-live="polite"></p>
    </div>
@endif
