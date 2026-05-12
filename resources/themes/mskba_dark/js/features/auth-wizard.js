import $ from 'jquery';

const AUTH_MODAL_NAME = 'auth-entry';

$(document).on('submit', '[data-auth-flow-form]', function(event) {
    event.preventDefault();

    const form = $(this);
    const passwordField = form.find('[data-auth-password-field]');
    const passwordVisible = !passwordField.is('[hidden]');

    if (passwordVisible) {
        submitPasswordStep(form);
        return;
    }

    resolveLoginStep(form);
});

$(document).on('click', '[data-auth-back]', function() {
    const form = $(this).closest('[data-auth-flow-form]');
    if (!form.length) {
        return;
    }

    resetAuthFlowState(form, true);
});

$(document).on('modal:opened', function(_event, modal) {
    if (modal.data('modal') !== AUTH_MODAL_NAME) {
        return;
    }

    modal.find('[data-auth-flow-form]').each(function() {
        resetAuthFlowState($(this), true);
    });
});

function resolveLoginStep(form) {
    const loginInput = form.find('[data-auth-login-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const statusNode = form.find('[data-auth-status]');

    const loginValue = String(loginInput.val() || '').trim();
    if (!loginValue) {
        setAuthStatus(statusNode, 'Введите логин, email или телефон.', 'error');
        return;
    }

    submitButton.prop('disabled', true);

    $.ajax({
        url: form.attr('action'),
        method: 'POST',
        dataType: 'json',
        data: {
            _token: form.find('[name="_token"]').val(),
            login: loginValue
        }
    }).done(function(response) {
        applyResolveResponse(form, response);
    }).fail(function(xhr) {
        const response = xhr.responseJSON || {};
        applyResolveResponse(form, response, true);
    }).always(function() {
        submitButton.prop('disabled', false);
    });
}

function submitPasswordStep(form) {
    const passwordInput = form.find('[data-auth-password-input]');
    const statusNode = form.find('[data-auth-status]');

    if (!String(passwordInput.val() || '').trim()) {
        setAuthStatus(statusNode, 'Введите пароль.', 'error');
        return;
    }

    setAuthStatus(statusNode, 'Эмуляция: пароль принят, проверка на backend будет подключена следующим шагом.', 'success');
}

function applyResolveResponse(form, response, requestFailed = false) {
    const status = response.status || '';
    const message = response.message || 'Не удалось обработать запрос. Попробуйте ещё раз.';
    const loginInput = form.find('[data-auth-login-input]');
    const passwordField = form.find('[data-auth-password-field]');
    const passwordInput = form.find('[data-auth-password-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const statusNode = form.find('[data-auth-status]');
    const backButton = form.find('[data-auth-back]');

    if (status === 'password_required') {
        loginInput.prop('readonly', true);
        passwordField.removeAttr('hidden').focus();
        passwordInput.prop('required', true);
        submitButton.text('Войти');
        backButton.removeAttr('hidden');
        setAuthStatus(statusNode, message, 'info');
        return;
    }

    resetAuthFlowState(form);

    if (status === 'code_sent') {
        loginInput.prop('readonly', true);
        backButton.removeAttr('hidden');
        setAuthStatus(statusNode, message, 'success');
        return;
    }

    if (status === 'user_not_found' || requestFailed) {
        setAuthStatus(statusNode, message, 'error');
        return;
    }

    setAuthStatus(statusNode, message, 'info');
}

function setAuthStatus(node, text, state) {
    node.text(text).attr('data-state', state || 'info');
}

function resetAuthFlowState(form, focusLogin = false) {
    const passwordField = form.find('[data-auth-password-field]');
    const passwordInput = form.find('[data-auth-password-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const statusNode = form.find('[data-auth-status]');
    const backButton = form.find('[data-auth-back]');
    const loginInput = form.find('[data-auth-login-input]');

    loginInput.prop('readonly', false);
    passwordField.attr('hidden', true);
    passwordInput.prop('required', false).val('');
    submitButton.text('Продолжить');
    backButton.attr('hidden', true);
    statusNode.text('').removeAttr('data-state');

    if (focusLogin) {
        loginInput.trigger('focus');
    }
}
