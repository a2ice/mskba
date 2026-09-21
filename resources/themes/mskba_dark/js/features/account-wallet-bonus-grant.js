const amountInput = document.querySelector('[data-wallet-bonus-grant-amount]');
const openButton = document.querySelector('[data-wallet-bonus-grant-open]');
const modalAmountInput = document.querySelector('[data-wallet-bonus-grant-modal-amount]');
const amountDisplay = document.querySelector('[data-wallet-bonus-grant-display]');
const passwordInput = document.querySelector('[data-wallet-bonus-grant-password]');

const parseGrantAmount = (rawValue) => {
    const raw = String(rawValue || '').trim();
    const normalized = raw.replace(/\s+/g, '').replace(',', '.');

    if (!/^\d{1,9}(?:\.\d{1,2})?$/.test(normalized)) {
        return null;
    }

    const amount = Number(normalized);
    if (!Number.isFinite(amount) || amount <= 0) {
        return null;
    }

    const fractionDigits = normalized.includes('.') ? 2 : 0;
    const display = new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: 2,
    }).format(amount);

    return {
        value: normalized,
        display: `${display} ₽`,
    };
};

const syncGrantAmount = (parsedAmount) => {
    if (modalAmountInput) {
        modalAmountInput.value = parsedAmount?.value || '';
    }

    if (amountDisplay) {
        amountDisplay.textContent = parsedAmount?.display || '—';
    }
};

const rejectInvalidAmount = () => {
    amountInput.setCustomValidity('Укажите корректную сумму в рублях, не более двух знаков после запятой.');
    amountInput.reportValidity();
    amountInput.focus();
};

if (amountInput && openButton && modalAmountInput) {
    amountInput.addEventListener('input', () => {
        amountInput.setCustomValidity('');
    });

    openButton.addEventListener('click', (event) => {
        const parsedAmount = parseGrantAmount(amountInput.value);

        if (!parsedAmount) {
            event.preventDefault();
            event.stopImmediatePropagation();
            rejectInvalidAmount();
            return;
        }

        amountInput.setCustomValidity('');
        syncGrantAmount(parsedAmount);

        window.setTimeout(() => passwordInput?.focus(), 0);
    }, true);

    syncGrantAmount(parseGrantAmount(modalAmountInput.value || amountInput.value));

    if (document.querySelector('[data-modal="wallet-bonus-grant-confirm"].is-open')) {
        window.setTimeout(() => passwordInput?.focus(), 0);
    }
}
