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

$(document).on('click', '[data-telegram-bot-login]', function(event) {
    event.preventDefault();

    const container = $(this).closest('[data-telegram-login]');
    const activeRequest = container.data('telegramBotStartRequest');

    if (!container.length || (activeRequest && activeRequest.readyState !== 4)) {
        return;
    }

    stopTelegramBotLoginPolling(container);

    const endpoint = String(container.data('telegramBotStartUrl') || '').trim();
    const modal = container.closest('[data-modal]');
    const redirectUrl = modal.length
        ? String(modal.data('authRedirectUrl') || '').trim()
        : '';
    const telegramWindow = window.open('', 'mskba-telegram-login');

    if (telegramWindow) {
        telegramWindow.opener = null;
        telegramWindow.document.title = 'Telegram';
    }

    setTelegramLoginState(container, true, 'Готовим безопасный вход…', 'info');

    const request = $.ajax({
        url: endpoint,
        method: 'POST',
        dataType: 'json',
        headers: {
            Accept: 'application/json'
        },
        data: {
            redirect_to: redirectUrl
        }
    });

    container.data('telegramBotStartRequest', request);

    request
        .done(function(response) {
            const token = String(response.token || '').trim();
            const botUrl = String(response.bot_url || '').trim();

            if (!token || !botUrl) {
                if (telegramWindow) {
                    telegramWindow.close();
                }

                setTelegramLoginState(container, false, 'Не удалось подготовить вход через Telegram.', 'error');
                return;
            }

            container.data('telegramBotLoginToken', token);
            setTelegramLoginState(
                container,
                true,
                response.message || 'Подтвердите вход в боте. Оставьте страницу открытой.',
                'info'
            );

            if (telegramWindow) {
                telegramWindow.location.replace(botUrl);
            } else {
                window.location.assign(botUrl);
            }

            scheduleTelegramBotLoginPoll(container);
        })
        .fail(function(jqXHR) {
            if (telegramWindow) {
                telegramWindow.close();
            }

            const response = jqXHR.responseJSON || {};
            const validationMessage = Object.values(response.errors || {}).flat().find(Boolean);

            setTelegramLoginState(
                container,
                false,
                response.message || validationMessage || 'Не удалось начать вход через Telegram.',
                'error'
            );
        })
        .always(function() {
            container.removeData('telegramBotStartRequest');
        });
});

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
    modal.find('[data-telegram-login]').each(function() {
        stopTelegramBotLoginPolling($(this));
    });
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

function scheduleTelegramBotLoginPoll(container) {
    stopTelegramBotLoginPolling(container, false);

    const timer = window.setTimeout(function() {
        pollTelegramBotLogin(container);
    }, 1400);

    container.data('telegramBotLoginTimer', timer);
}

function pollTelegramBotLogin(container) {
    const endpoint = String(container.data('telegramBotStatusUrl') || '').trim();
    const token = String(container.data('telegramBotLoginToken') || '').trim();

    if (!endpoint || !token) {
        stopTelegramBotLoginPolling(container);
        setTelegramLoginState(container, false, 'Не удалось проверить вход через Telegram.', 'error');
        return;
    }

    const request = $.ajax({
        url: endpoint,
        method: 'POST',
        dataType: 'json',
        headers: {
            Accept: 'application/json'
        },
        data: {
            token: token
        }
    });

    container.data('telegramBotStatusRequest', request);

    request
        .done(function(response) {
            if (response.status === 'success') {
                stopTelegramBotLoginPolling(container);
                setTelegramLoginState(container, false, response.message || 'Вход выполнен.', 'success');
                redirectAfterAuthentication(response);
                return;
            }

            setTelegramLoginState(container, true, response.message || 'Ожидаем подтверждение в Telegram…', 'info');
            scheduleTelegramBotLoginPoll(container);
        })
        .fail(function(jqXHR) {
            const response = jqXHR.responseJSON || {};

            if (jqXHR.status === 410 || response.status === 'expired') {
                stopTelegramBotLoginPolling(container);
                setTelegramLoginState(
                    container,
                    false,
                    response.message || 'Ссылка для входа истекла. Запустите вход ещё раз.',
                    'error'
                );
                return;
            }

            scheduleTelegramBotLoginPoll(container);
        })
        .always(function() {
            container.removeData('telegramBotStatusRequest');
        });
}

function stopTelegramBotLoginPolling(container, clearToken = true) {
    const timer = Number(container.data('telegramBotLoginTimer') || 0);

    if (timer) {
        window.clearTimeout(timer);
    }

    container.removeData('telegramBotLoginTimer');

    if (clearToken) {
        container.removeData('telegramBotLoginToken');
    }
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
