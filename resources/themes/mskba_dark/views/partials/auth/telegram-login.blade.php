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
            <span>или через Telegram</span>
        </div>

        {{-- Временно скрыт прямой вход через Telegram Login Widget.
        <div class="auth-telegram-login__option">
            <span class="auth-telegram-login__option-label">Быстрый вход</span>
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
        </div>

        <span class="auth-telegram-login__alternative">или</span>
        --}}

        <button
            type="button"
            class="btn btn--sm auth-telegram-login__button"
            data-telegram-bot-login
        >
            <svg class="auth-telegram-login__icon" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d="m9.78 18.65.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-2 1.93c-.23.23-.42.42-.82.42Z"/>
            </svg>
            <span data-telegram-bot-login-label>Войти через Telegram-бота</span>
        </button>

        <p class="auth-telegram-login__message form-message" aria-live="polite"></p>
    </div>
@endif
