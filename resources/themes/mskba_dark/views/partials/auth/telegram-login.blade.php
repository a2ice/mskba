@php
    $telegramBotUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
@endphp

@if($telegramBotUsername !== '')
    <div
        class="auth-telegram-login"
        data-telegram-login
        data-telegram-login-url="{{ route('auth.telegram', [], false) }}"
        data-telegram-bot-start-url="{{ route('auth.telegram.bot.start', [], false) }}"
        data-telegram-bot-status-url="{{ route('auth.telegram.bot.status', [], false) }}"
    >
        <div class="auth-telegram-login__separator" aria-hidden="true">
            <span>или</span>
        </div>

        <button
            type="button"
            class="btn btn--primary btn--sm auth-telegram-login__button"
            data-telegram-bot-login
        >
            Войти через Telegram
        </button>

        {{-- Резервный вариант: официальный Login Widget сохраняем, но в основном UI не показываем. --}}
        <div class="auth-telegram-login__widget" hidden aria-hidden="true">
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
