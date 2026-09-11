function resetVisibleVenueSlotPrices(dialog) {
    dialog.querySelectorAll('[data-venue-price-row]:not([hidden]) input.form-control').forEach((input) => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function setupVenuePricingResetAll() {
    document.querySelectorAll('[data-venue-price-dialog]').forEach((dialog) => {
        const footer = dialog.querySelector('.account-venue-price-dialog__footer');
        const doneButton = footer?.querySelector('[data-venue-price-dialog-close]');

        if (!footer || !doneButton || footer.querySelector('[data-venue-price-reset-all]')) {
            return;
        }

        const tooltip = 'Сбрасывает индивидуальные цены всех слотов этого интервала. После сохранения будет использоваться базовая стоимость из условий аренды.';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'account-venue-price-dialog__reset-all ui-tooltip-source ui-tooltip-source--title';
        button.dataset.venuePriceResetAll = '';
        button.dataset.tooltip = tooltip;
        button.setAttribute('aria-label', `Сбросить у всех. ${tooltip}`);
        button.textContent = 'Сбросить у всех';

        button.addEventListener('click', () => {
            resetVisibleVenueSlotPrices(dialog);
        });

        doneButton.before(button);
    });
}

document.addEventListener('DOMContentLoaded', setupVenuePricingResetAll);
