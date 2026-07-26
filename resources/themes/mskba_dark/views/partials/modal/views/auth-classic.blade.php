@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
@endphp

<div class="modal-auth auth-classic">

    <section class="auth-classic__section" data-auth-classic-section="login">
        <h2 class="modal-auth__title modal_title" id="modal-title-auth-entry-classic">Вход в аккаунт</h2>

        <form class="auth-form" action="{{ route('auth.login', [], false) }}" method="POST" data-auth-classic-form data-auth-classic-kind="login">
            <input type="hidden" name="redirect_to" value="" data-auth-redirect-input>

            <label class="auth-form__field">
                <span>Логин или подтверждённый контакт</span>
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

        @include('theme::partials.auth.telegram-login')

        <p class="auth-classic__links">
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="restore">Восстановить доступ</button>
            <button type="button" class="auth-classic__link" data-auth-classic-link data-auth-classic-target="register">Регистрация</button>
        </p>
    </section>

    <section class="auth-classic__section" data-auth-classic-section="register" hidden>
        <h2 class="modal-auth__title modal_title" id="modal-title-auth-entry-classic">Регистрация</h2>

        <form class="auth-form" data-auth-classic-form data-auth-classic-kind="register" action="{{ route('auth.register', [], false) }}" method="POST">
            <input type="hidden" name="redirect_to" value="" data-auth-redirect-input>
            <label class="auth-form__field">
                <span>Логин</span>
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>

            <label class="auth-form__field">
                <span>Пароль</span>
                <input type="password" name="password" autocomplete="new-password" required>
            </label>

            <label class="auth-form__field">
                <span>Подтверждение пароля</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>

            <label class="auth-form__field">
                <span>Роль на проекте</span>
                <select name="role" class="form-select">
                    <option value="">Выбрать позже</option>
                    @foreach(UserParticipationRoleEnum::cases() as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="privacy-consent privacy-consent--compact">
                <input class="privacy-consent__input" type="checkbox" name="privacy_consent" value="1" required>
                <span class="privacy-consent__control" aria-hidden="true"></span>
                <span class="privacy-consent__text">
                    Я даю согласие на обработку персональных данных и принимаю условия
                    <a href="{{ route('privacy.policy') }}" target="_blank" rel="noopener">Политики обработки персональных данных</a>.
                </span>
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
        <h2 class="modal-auth__title modal_title" id="modal-title-auth-entry-classic">Восстановление доступа</h2>

        <form class="auth-form" data-auth-classic-form data-auth-classic-kind="restore" action="{{ route('auth.restore', [], false) }}" method="POST">
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
