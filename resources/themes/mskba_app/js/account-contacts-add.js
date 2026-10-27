import { createContactProviderEmulation } from './contact-provider-emulation.js';
import { createTelegramContactBotLink } from './telegram-contact-bot-link.js';
import { refreshAccountContactsList } from './account-contacts-list.js';
const root = document.querySelector('[data-contacts-add]');

if (root) {
    const dialog = root.querySelector('[data-contact-add-modal]');
    const steps = [...dialog.querySelectorAll('[data-contact-add-step]')];
    const error = dialog.querySelector('[data-contact-add-error]');
    const value = dialog.querySelector('[data-contact-add-value]');
    const label = dialog.querySelector('[data-contact-add-value-label]');
    const success = dialog.querySelector('[data-contact-add-success]');
    const back = dialog.querySelector('[data-contact-add-back]');
    const next = dialog.querySelector('[data-contact-add-next]');
    const footer = dialog.querySelector('[data-contact-add-footer]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let step = 'method';
    let kind = 'email';
    let busy = false;
    let opener = null;
    let previousOverflow = '';

    const setError = text => {
        error.textContent = text || '';
        error.hidden = !text;
        dialog.dataset.surfaceTone = text ? 'error' : (step === 'done' ? 'success' : 'neutral');
    };

    const setBusy = enabled => {
        busy = enabled;
        next.disabled = enabled;
        back.disabled = enabled;
        dialog.setAttribute('aria-busy', enabled ? 'true' : 'false');
    };

    function showStep(target) {
        step = target;
        setError('');
        steps.forEach(node => { node.hidden = node.dataset.contactAddStep !== target; });
        if (target === 'done') dialog.querySelector('.mskba-modal__body')?.scrollTo(0, 0);
        footer.hidden = target === 'done';
        next.hidden = !['method', 'value'].includes(target);
        back.hidden = target === 'method';
        next.textContent = target === 'value' ? 'Добавить контакт' : 'Далее';
        if (target === 'value') value.focus();
        if (target === 'telegram') telegramLink.start();
        else telegramLink.stop();
    }

    function open(preselected = 'email') {
        opener = document.activeElement;
        const radio=dialog.querySelector('input[name="new_contact_channel"][value="'+preselected+'"]');
        (radio && !radio.disabled ? radio : dialog.querySelector('input[name="new_contact_channel"][value="email"]')).checked = true;
        value.value = '';
        kind = preselected;
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[tabindex="-1"]')?.focus({ preventScroll: true });
        showStep('method');
        emulator.reset();
    }

    function configureValueField() {
        const isEmail = kind === 'email';
        label.textContent = isEmail ? 'Адрес электронной почты' : 'Номер телефона';
        value.type = isEmail ? 'email' : 'tel';
        value.inputMode = isEmail ? 'email' : 'tel';
        value.autocomplete = isEmail ? 'email' : 'tel';
        value.placeholder = isEmail ? 'name@example.com' : '+79991234567';
        value.value = '';
    }

    async function post(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstError = Object.values(data.errors || {}).flat().find(Boolean);
            const exception = new Error(firstError || data.message || 'Не удалось добавить контакт.');
            exception.data = data;
            exception.payload = data;
            exception.status = response.status;
            throw exception;
        }
        return data;
    }

    const refreshList = refreshAccountContactsList;

    const telegramLink = createTelegramContactBotLink({
        root, dialog, post,
        onSuccess: async result => {
            let updated = true;
            try { await refreshList(); } catch { updated = false; }
            success.textContent = (result.message || 'Telegram подтверждён и добавлен в список.')
                + (updated ? '' : ' Обнови страницу, чтобы увидеть контакт.');
            window.dispatchEvent(new CustomEvent('mskba:contact:updated'));
            showStep('done');
        },
        onError: message => setError(message),
    });

    const emulator = createContactProviderEmulation({
        root, dialog, post,
        onBusy: setBusy,
        onError: setError,
        onSuccess: async result => {
            let updated = true;
            try { await refreshList(); } catch { updated = false; }
            success.textContent = result.message
                + (updated ? '' : ' Обнови страницу, чтобы увидеть контакт.');
            showStep('done');
        },
    });

    root.querySelector('[data-contact-add-open]').addEventListener('click', () => open());
    window.addEventListener('mskba:contact:add', event => open(event.detail?.type || 'email'));
    dialog.querySelectorAll('[data-contact-add-close]').forEach(button => {
        button.addEventListener('click', () => { if (!busy) dialog.close(); });
    });
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        telegramLink.stop();
        opener?.focus();
    });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('click', event => { if (event.target === dialog && !busy) dialog.close(); });

    dialog.querySelector('[data-contact-add-again]').addEventListener('click', () => {
        showStep('method');
        value.value = '';
    });
    back.addEventListener('click', () => { if (!busy) showStep('method'); });

    next.addEventListener('click', async () => {
        if (busy) return;
        if (step === 'method') {
            kind = dialog.querySelector('input[name="new_contact_channel"]:checked')?.value || '';
            if (!kind) { setError('Выбери способ связи.'); return; }
            if (kind === 'email' || kind === 'phone') {
                configureValueField();
                showStep('value');
            } else if (emulator.available && ['telegram', 'vk'].includes(kind)) {
                emulator.choose(kind);
                showStep('emulation');
            } else {
                showStep(kind);
            }
            return;
        }

        if (step !== 'value') return;
        const entered = value.value.trim();
        if (kind === 'email') {
            value.value = entered;
            if (!value.reportValidity()) return;
        } else {
            const normalized = entered.replace(/[ ()-]/g, '');
            if (!/^\+?[0-9]{7,15}$/.test(normalized)) {
                setError('Некорректный формат телефона.');
                return;
            }
            value.value = normalized;
        }
        setBusy(true);
        setError('');
        try {
            const result = await post(root.dataset.addUrl, { type: kind, value: value.value });
            let updated = true;
            try { await refreshList(); } catch { updated = false; }
            success.textContent = (result.contact?.value || value.value)
                + ' — контакт добавлен. ' + (updated
                    ? 'Подтвердить его можно в списке контактов.'
                    : 'Не удалось обновить список автоматически. Обнови страницу, чтобы увидеть контакт.');
            showStep('done');
        } catch (exception) {
            // Addition may already have succeeded even if the list-refresh request failed.
            if (exception instanceof Error) setError(exception.message);
        } finally {
            setBusy(false);
        }
    });

}
