import $ from 'jquery';
import * as forms from '../../core/forms.js';

const CLASSIC_MODAL = 'auth-entry-classic';
const DEFAULT_SECTION = 'login';

$(document).on('click', '[data-auth-classic-link]', function(event) {
    event.preventDefault();

    const trigger = $(this);
    const modal = trigger.closest('[data-modal]');
    const target = String(trigger.data('authClassicTarget') || trigger.data('auth-classic-target') || '').trim();

    if (!modal.length || !target) {
        return;
    }

    resetClassicFormStates(modal);
    activateSection(modal, target);
});

$(document).on('submit', '[data-auth-classic-form]', function(event) {
    event.preventDefault();

    const form = $(this);
    const kind = String(form.data('authClassicKind') || form.data('auth-classic-kind') || '').trim();

    forms.submitForm(form, {
        onSuccess(response) {
            if (kind === 'register') {
                prepareLoginAfterRegistration(form.closest('[data-modal]'), response);
                return;
            }

            if (kind === 'login') {
                location.reload();
            }
        },
        onError(jqXHR) {
            console.log('Login failed');
            console.log(jqXHR.responseJSON);
        }
    });

});

$(document).on('modal:opened', function(_event, modal) {
    if (!isClassicAuthModal(modal)) {
        return;
    }

    resetClassicModalState(modal);
    activateSection(modal, DEFAULT_SECTION);
});

$(document).on('modal:closed', function(_event, modal) {
    if (!isClassicAuthModal(modal)) {
        return;
    }

    resetClassicModalState(modal);
});

function isClassicAuthModal(modal) {
    return modal.data('modal') === CLASSIC_MODAL;
}

function resetClassicModalState(modal) {
    modal.find('[data-auth-classic-form]').each(function() {
        this.reset();
        forms.resetFormState(this);
    });
}

function resetClassicFormStates(modal) {
    modal.find('[data-auth-classic-form]').each(function() {
        forms.resetFormState(this);
    });
}

function prepareLoginAfterRegistration(modal, response) {
    const login = String(response.login || '').trim();
    const message = response.message || 'Мы отправили временный пароль на email. Введите его для входа.';
    const loginForm = modal.find('[data-auth-classic-form][data-auth-classic-kind="login"]');
    const passwordInput = loginForm.find('input[name="password"]');

    forms.resetFormState(loginForm);

    if (login) {
        loginForm.find('input[name="login"]').val(login);
    }

    forms.setFormMessage(loginForm, message, 'success');
    activateSection(modal, 'login', ()=> passwordInput.trigger('focus'));
}

function activateSection(modal, target, callback) {
    const sections = modal.find('[data-auth-classic-section]');
    const targetSection = modal.find('[data-auth-classic-section="' + target + '"]');

    if (!targetSection.length) {
        return;
    }

    sections.attr('hidden', true);
    targetSection.removeAttr('hidden');
    if(typeof callback === 'function') {
        callback(targetSection);
    } else {
        targetSection.find('[autofocus]').first().trigger('focus');
    }
}
