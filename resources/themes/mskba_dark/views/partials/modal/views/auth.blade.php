<div class="modal-auth">
    <h2 class="modal-auth__title" id="modal-title-auth-entry">Вход и регистрация</h2>

    <div class="tabs-wrapper">
        <div class="tabs__panel" role="tabpanel" id="auth-login">
            @include('theme::partials.modal.views.auth.login')
        </div>

        <div class="tabs__panel" role="tabpanel" id="auth-register" hidden>
            @include('theme::partials.modal.views.auth.register')
        </div>
    </div>
</div>
