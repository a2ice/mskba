<div class="modal-auth auth-classic">

    <section class="auth-classic__section" data-auth-classic-section="login">
        <h2 class="modal-auth__title" id="modal-title-auth-entry-classic">Вход в аккаунт</h2>

        <form class="auth-form" action="{{ route('auth.login') }}" method="POST" data-auth-classic-form data-auth-classic-kind="login">

            <label class="auth-form__field">
                <span>Логин / Email</span>
                <input type="text" name="login" autocomplete="username" required autofocus>
            </label>

            <label class="auth-form__field">
                <span>Пароль</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>

            <label class="auth-classic__remember">
                <input type="checkbox" name="remember">
                <span>Запомнить меня</span>
            </label>

            <div class="auth-form__message form-message"></div>

            <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Войти</button>
        </form>

        <p class="auth-classic__links">
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="restore">Восстановить доступ</button>
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="register">Регистрация</button>
        </p>
    </section>

    <section class="auth-classic__section" data-auth-classic-section="register" hidden>
        <h2 class="modal-auth__title" id="modal-title-auth-entry-classic">Регистрация</h2>

        <form class="auth-form" data-auth-classic-form data-auth-classic-kind="register" action="{{ route('auth.register') }}" method="POST">
            <label class="auth-form__field">
                <span>Email</span>
                <input type="email" name="email" autocomplete="username" required autofocus>
            </label>

            <div class="auth-form__message form-message"></div>

            <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Зарегистрироваться</button>
        </form>

        <p class="auth-classic__links">
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="login">Войти на сайт</button>
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="restore">Восстановить доступ</button>
        </p>
    </section>

    <section class="auth-classic__section" data-auth-classic-section="restore" hidden>
        <h2 class="modal-auth__title" id="modal-title-auth-entry-classic">Восстановление доступа</h2>

        <form class="auth-form" data-auth-classic-form data-auth-classic-kind="restore" action="{{ route('auth.restore') }}" method="POST">
            <label class="auth-form__field">
                <span>Email</span>
                <input type="email" name="contact" autocomplete="email" required autofocus>
            </label>

            <div class="auth-form__message form-message"></div>

            <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Получить новый пароль</button>
        </form>

        <p class="auth-classic__links">
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="login">Войти на сайт</button>
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="register">Регистрация</button>
        </p>
    </section>
</div>
