<div class="modal-auth">
    <h2 class="modal-auth__title" id="modal-title-auth-entry">Вход на сайт</h2>

    <p class="modal-description" title="Если пользователь найден и у него есть пароль — попросим пароль.
        Если пароля нет или указан контакт нового пользователя — отправим одноразовый код.">
        Введите логин, email или телефон.
    </p>

    @include('theme::partials.modal.views.auth.login')
</div>
