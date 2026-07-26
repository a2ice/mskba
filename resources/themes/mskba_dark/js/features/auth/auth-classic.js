import $ from 'jquery';
import * as forms from '../../core/forms.js';

const CLASSIC_MODAL = 'auth-entry-classic';
const DEFAULT_SECTION = 'login';

window.mskbaTelegramLogin = function(telegramUser) {
    const container = getActiveTelegramLoginContainer();

    if (!container.length || !telegramUser) {
        return;
    }

    const activeRequest = container.data('telegramLoginRequest');
    if (activeRequest && activeRequest.readyState !== 4) {
        return;
    }

    const endpoint = String(container.data('telegramLoginUrl') || '').trim();
    const modal = container.closest('[data-modal]');
    const redirectUrl = modal.length
        ? String(modal.data('authRedirectUrl') || '').trim()
        : '';

    setTelegramLoginState(container, true, 'Проверяем данные Telegram…', 'info');

    const request = $.ajax({
        url: endpoint,
        method: 'POST',
        dataType: 'json',
        headers: {
            Accept: 'application/json'
        },
        data: {
            telegram_user: telegramUser,
            redirect_to: redirectUrl
        }
    });

    container.data('telegramLoginRequest', request);

    request
        .done(function(response) {
            setTelegramLoginState(container, false, response.message || 'Вход выполнен.', 'success');
            redirectAfterAuthentication(response);
        })
        .fail(function(jqXHR) {
            const response = jqXHR.responseJSON || {};
            const validationMessage = Object.values(response.errors || {}).flat().find(Boolean);
            const message = response.message || validationMessage || 'Не удалось войти через Telegram.';

            setTelegramLoginState(container, false, message, 'error');
        })
        .always(function() {
            container.removeData('telegramLoginRequest');
        });
};

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
                redirectAfterAuthentication(response);
                return;
            }

            if (kind === 'login') {
                redirectAfterAuthentication(response);
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
    modal.find('[data-auth-redirect-input]').val(String(modal.data('authRedirectUrl') || '').trim());
    activateSection(modal, getInitialSection(modal));
    modal.removeData('modalInitialSection');
});

$(document).on('modal:closed', function(_event, modal) {
    if (!isClassicAuthModal(modal)) {
        return;
    }

    resetClassicModalState(modal);
    modal.removeData('authRedirectUrl');
});

function isClassicAuthModal(modal) {
    return modal.data('modal') === CLASSIC_MODAL;
}

function getInitialSection(modal) {
    const initialSection = String(modal.data('modalInitialSection') || '').trim();

    return initialSection || DEFAULT_SECTION;
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

function redirectAfterAuthentication(response) {
    const redirectUrl = String(response.redirect_url || '').trim();

    if (redirectUrl) {
        window.location.assign(redirectUrl);
        return;
    }

    window.location.reload();
}

function getActiveTelegramLoginContainer() {
    const visibleContainer = $('[data-telegram-login]:visible').first();

    return visibleContainer.length ? visibleContainer : $('[data-telegram-login]').first();
}

function setTelegramLoginState(container, isSubmitting, message, state) {
    container
        .toggleClass('is-submitting', isSubmitting)
        .attr('aria-busy', isSubmitting ? 'true' : 'false');

    container.find('.auth-telegram-login__message')
        .text(message || '')
        .attr('data-state', state || 'info');
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
