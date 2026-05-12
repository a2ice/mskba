import $ from 'jquery';

const AUTH_MODAL_NAME = 'auth-entry';

$(document).on('submit', '[data-auth-flow-form]', function(event) {
    event.preventDefault();

    const form = $(this);
    const passwordField = form.find('[data-auth-password-field]');
    const codeField = form.find('[data-auth-code-field]');
    const passwordVisible = !passwordField.is('[hidden]');
    const codeVisible = !codeField.is('[hidden]');

    if (codeVisible) {
        submitCodeStep(form);
        return;
    }

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
    const challengeInput = form.find('[data-auth-challenge-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const passwordInput = form.find('[data-auth-password-input]');
    const statusNode = form.find('[data-auth-status]');

    if (!String(passwordInput.val() || '').trim()) {
        setAuthStatus(statusNode, 'Введите пароль.', 'error');
        return;
    }

    if (!String(challengeInput.val() || '').trim()) {
        setAuthStatus(statusNode, 'Сессия входа истекла. Начните заново.', 'error');
        return;
    }

    submitButton.prop('disabled', true);

    $.ajax({
        url: form.data('auth-verify-url'),
        method: 'POST',
        dataType: 'json',
        data: {
            _token: form.find('[name="_token"]').val(),
            challenge: String(challengeInput.val() || '').trim(),
            password: String(passwordInput.val() || '').trim()
        }
    }).done(function(response) {
        applyVerifyResponse(form, response);
    }).fail(function(xhr) {
        const response = xhr.responseJSON || {};
        applyVerifyResponse(form, response, true);
    }).always(function() {
        submitButton.prop('disabled', false);
    });
}

function submitCodeStep(form) {
    const challengeInput = form.find('[data-auth-challenge-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const codeInput = form.find('[data-auth-code-input]');
    const statusNode = form.find('[data-auth-status]');

    if (!String(codeInput.val() || '').trim()) {
        setAuthStatus(statusNode, 'Введите одноразовый код.', 'error');
        return;
    }

    if (!String(challengeInput.val() || '').trim()) {
        setAuthStatus(statusNode, 'Сессия входа истекла. Начните заново.', 'error');
        return;
    }

    submitButton.prop('disabled', true);

    $.ajax({
        url: form.data('auth-verify-url'),
        method: 'POST',
        dataType: 'json',
        data: {
            _token: form.find('[name="_token"]').val(),
            challenge: String(challengeInput.val() || '').trim(),
            code: String(codeInput.val() || '').trim()
        }
    }).done(function(response) {
        applyVerifyResponse(form, response);
    }).fail(function(xhr) {
        const response = xhr.responseJSON || {};
        applyVerifyResponse(form, response, true);
    }).always(function() {
        submitButton.prop('disabled', false);
    });
}

function applyVerifyResponse(form, response, requestFailed = false) {
    const message = response.message || 'Не удалось выполнить проверку. Попробуйте ещё раз.';
    const statusNode = form.find('[data-auth-status]');

    if (requestFailed) {
        setAuthStatus(statusNode, message, 'error');
        return;
    }

    setAuthStatus(statusNode, message, 'success');
}

function applyResolveResponse(form, response, requestFailed = false) {
    const status = response.status || '';
    const message = response.message || 'Не удалось обработать запрос. Попробуйте ещё раз.';
    const challenge = String(response.challenge || '').trim();
    const challengeInput = form.find('[data-auth-challenge-input]');
    const loginInput = form.find('[data-auth-login-input]');
    const passwordField = form.find('[data-auth-password-field]');
    const passwordInput = form.find('[data-auth-password-input]');
    const codeField = form.find('[data-auth-code-field]');
    const codeInput = form.find('[data-auth-code-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const statusNode = form.find('[data-auth-status]');
    const backButton = form.find('[data-auth-back]');

    if (status === 'password_required') {
        if (!challenge) {
            setAuthStatus(statusNode, 'Не удалось подготовить сессию входа. Повторите попытку.', 'error');
            return;
        }

        challengeInput.val(challenge);
        loginInput.prop('readonly', true);
        passwordField.removeAttr('hidden');
        passwordInput.prop('required', true);
        codeField.attr('hidden', true);
        codeInput.prop('required', false).val('');
        submitButton.text('Войти');
        backButton.removeAttr('hidden');
        setAuthStatus(statusNode, message, 'info');
        passwordInput.trigger('focus');
        return;
    }

    resetAuthFlowState(form);

    if (status === 'code_sent') {
        if (!challenge) {
            setAuthStatus(statusNode, 'Не удалось подготовить сессию входа. Повторите попытку.', 'error');
            return;
        }

        challengeInput.val(challenge);
        loginInput.prop('readonly', true);
        codeField.removeAttr('hidden');
        codeInput.prop('required', true);
        backButton.removeAttr('hidden');
        submitButton.text('Подтвердить код');
        setAuthStatus(statusNode, message, 'success');
        codeInput.trigger('focus');
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
    const challengeInput = form.find('[data-auth-challenge-input]');
    const passwordField = form.find('[data-auth-password-field]');
    const passwordInput = form.find('[data-auth-password-input]');
    const codeField = form.find('[data-auth-code-field]');
    const codeInput = form.find('[data-auth-code-input]');
    const submitButton = form.find('[data-auth-submit-button]');
    const statusNode = form.find('[data-auth-status]');
    const backButton = form.find('[data-auth-back]');
    const loginInput = form.find('[data-auth-login-input]');

    loginInput.prop('readonly', false);
    challengeInput.val('');
    passwordField.attr('hidden', true);
    passwordInput.prop('required', false).val('');
    codeField.attr('hidden', true);
    codeInput.prop('required', false).val('');
    submitButton.text('Продолжить');
    backButton.attr('hidden', true);
    statusNode.text('').removeAttr('data-state');

    if (focusLogin) {
        loginInput.trigger('focus');
    }
}
