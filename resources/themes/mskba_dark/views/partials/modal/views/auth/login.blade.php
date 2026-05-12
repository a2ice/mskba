<form method="POST" action="{{ route('auth.resolve-login') }}" class="auth-form ajax-form" data-auth-flow-form>
    @csrf

    <label class="auth-form__field">
        <span>Логин, email или телефон</span>
        <input type="text" name="login" autocomplete="username" required data-auth-login-input>
    </label>

    <label class="auth-form__field" data-auth-password-field hidden>
        <span>Пароль</span>
        <input type="password" name="password" autocomplete="current-password" data-auth-password-input>
    </label>

    <p class="auth-form__status" data-auth-status aria-live="polite"></p>

    <div class="auth-form__actions">
        <button
            type="button"
            class="auth-form__back auth-form__btn btn btn--sm"
            data-auth-back
            hidden
        >
            Назад
        </button>

        <button type="submit" class="btn btn--primary btn--sm auth-form__submit auth-form__btn" data-auth-submit-button>Продолжить</button>
    </div>
</form>
