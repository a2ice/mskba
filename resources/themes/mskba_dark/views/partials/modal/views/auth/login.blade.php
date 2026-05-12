<form method="POST" action="{{ route('login') }}" class="auth-form">
    @csrf

    <label class="auth-form__field">
        <span>Логин</span>
        <input type="text" name="login" autocomplete="login" required>
    </label>

    <label class="auth-form__field">
        <span>Пароль</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Войти</button>

    <p class="auth-form__switch">
        Ещё нет личного кабинета?
        <button
            type="button"
            class="auth-form__switch-link"
            data-modal-tab
            aria-controls="auth-register"
        >
            Зарегистрируйтесь
        </button>
    </p>
</form>
