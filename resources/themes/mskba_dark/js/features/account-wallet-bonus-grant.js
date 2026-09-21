const amountInput = document.querySelector('[data-wallet-bonus-grant-amount]');
const openButton = document.querySelector('[data-wallet-bonus-grant-open]');
const modalAmountInput = document.querySelector('[data-wallet-bonus-grant-modal-amount]');
const amountDisplay = document.querySelector('[data-wallet-bonus-grant-display]');
const passwordInput = document.querySelector('[data-wallet-bonus-grant-password]');

const syncGrantAmount = (rawValue) => {
    const value = String(rawValue || '').trim();

    if (modalAmountInput) {
        modalAmountInput.value = value;
    }

    if (amountDisplay) {
        amountDisplay.textContent = value ? `${value} ₽` : '—';
    }
};

if (amountInput && openButton && modalAmountInput) {
    amountInput.addEventListener('input', () => {
        amountInput.setCustomValidity('');
    });

    openButton.addEventListener('click', (event) => {
        const value = amountInput.value.trim();

        if (!value) {
            event.preventDefault();
            event.stopImmediatePropagation();
            amountInput.setCustomValidity('Укажите сумму начисления.');
            amountInput.reportValidity();
            amountInput.focus();
            return;
        }

        amountInput.setCustomValidity('');
        syncGrantAmount(value);

        window.setTimeout(() => passwordInput?.focus(), 0);
    }, true);

    syncGrantAmount(modalAmountInput.value || amountInput.value);

    if (document.querySelector('[data-modal="wallet-bonus-grant-confirm"].is-open')) {
        window.setTimeout(() => passwordInput?.focus(), 0);
    }
}
