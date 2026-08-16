import $ from 'jquery';

window.mskbaTelegramLink = function(telegramUser) {
    const container = $('[data-account-telegram-link]').first();

    if (!container.length || !telegramUser) {
        return;
    }

    const endpoint = String(container.data('accountTelegramLinkUrl') || '').trim();
    const message = container.find('[data-account-telegram-link-message]');

    if (!endpoint) {
        return;
    }

    message.removeClass('text-danger text-success').text('Проверяем Telegram…');

    $.ajax({
        url: endpoint,
        method: 'POST',
        dataType: 'json',
        data: { telegram_user: telegramUser },
    })
        .done(function(response) {
            message.addClass('text-success').text(response.message || 'Telegram подтверждён.');

            if (response.redirect_url) {
                window.location.assign(response.redirect_url);
            }
        })
        .fail(function(jqXHR) {
            const response = jqXHR.responseJSON || {};

            if (response.status === 'duplicate' && response.redirect_url) {
                window.location.assign(response.redirect_url);
                return;
            }

            const validationMessage = Object.values(response.errors || {}).flat().find(Boolean);
            message.addClass('text-danger').text(response.message || validationMessage || 'Не удалось подтвердить Telegram.');
        });
};
