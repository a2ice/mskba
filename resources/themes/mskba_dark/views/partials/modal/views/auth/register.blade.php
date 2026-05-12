<form method="POST" action="{{ route('register') }}" class="auth-form">
    @csrf

    <label class="auth-form__field">
        <span>Логин</span>
        <input type="text" name="login" autocomplete="username" required>
    </label>

    <label class="auth-form__field">
        <span>Email</span>
        <input type="email" name="email" autocomplete="email" required>
    </label>

    <label class="auth-form__field">
        <span>Пароль</span>
        <input type="password" name="password" autocomplete="new-password" required>
    </label>

    <label class="auth-form__field">
        <span>Подтверждение пароля</span>
        <input type="password" name="password_confirmation" autocomplete="new-password" required>
    </label>

    <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Создать аккаунт</button>

    <p class="auth-form__switch">
        Уже есть личный кабинет?
        <button
            type="button"
            class="auth-form__switch-link"
            data-modal-tab
            aria-controls="auth-login"
        >
            Войти
        </button>
    </p>
</form>
