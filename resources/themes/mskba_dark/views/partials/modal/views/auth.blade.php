<div class="modal-auth">
    <h2 class="modal-auth__title" id="modal-title-auth-entry">Вход и регистрация</h2>

    <p class="modal-description"></p>

    <form method="POST" action="" class="auth-form">
        @csrf

        <label class="auth-form__field">
            <span>Логин или контакт</span>
            <input type="text" name="login" autocomplete="login" required>
        </label>

        <button type="submit" class="btn btn--primary btn--sm auth-form__submit">Войти</button>
    </form>

    <!--
        <div class="tabs-wrapper">
            <div class="tabs__panel" role="tabpanel" id="auth-login">
                @include('theme::partials.modal.views.auth.login')
            </div>

            <div class="tabs__panel" role="tabpanel" id="auth-register" hidden>
                @include('theme::partials.modal.views.auth.register')
            </div>
        </div>
    -->
</div>
