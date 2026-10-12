import { createTelegramContactBotLink } from './telegram-contact-bot-link.js';
const root = document.querySelector('[data-contact-wizard]');

if (root) {
    const dialog = root.querySelector('dialog');
    const stepNodes = [...dialog.querySelectorAll('[data-wizard-step]')];
    const emailInput = dialog.querySelector('[data-wizard-email]');
    const codeInput = dialog.querySelector('[data-wizard-code]');
    const errorNode = dialog.querySelector('[data-wizard-error]');
    const feedback = dialog.querySelector('[data-wizard-feedback]');
    const nextButton = dialog.querySelector('[data-wizard-next]');
    const backButton = dialog.querySelector('[data-wizard-back]');
    const resendButton = dialog.querySelector('[data-wizard-resend]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let step = 'method';
    let contactId = null;
    let isBusy = false;
    let cooldownInterval = null;
    let remainingSeconds = 0;
    let opener = null;
    let previousOverflow = '';

    function setError(message = '') {
        errorNode.textContent = message;
        errorNode.hidden = !message;
    }

    function setFeedback(message = '') {
        feedback.textContent = message;
        feedback.hidden = !message;
    }

    function setBusy(value) {
        isBusy = value;
        nextButton.disabled = value;
        backButton.disabled = value;
        resendButton.disabled = value || remainingSeconds > 0;
        dialog.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function cooldown(seconds = 0) {
        if (cooldownInterval) clearInterval(cooldownInterval);
        remainingSeconds = Math.max(0, Math.ceil(Number(seconds) || 0));
        const update = () => {
            resendButton.disabled = isBusy || remainingSeconds > 0;
            resendButton.textContent = remainingSeconds > 0
                ? 'Отправить повторно через ' + remainingSeconds + ' с'
                : 'Отправить новый код';
        };
        update();
        if (remainingSeconds > 0) {
            cooldownInterval = setInterval(() => {
                remainingSeconds = Math.max(0, remainingSeconds - 1);
                update();
                if (!remainingSeconds) clearInterval(cooldownInterval);
            }, 1000);
        }
    }

    function showStep(value) {
        step = value;
        setError();
        setFeedback();
        stepNodes.forEach(node => { node.hidden = node.dataset.wizardStep !== step; });
        backButton.hidden = step === 'method';
        nextButton.hidden = !['method', 'email', 'code'].includes(step);
        nextButton.textContent = step === 'code' ? 'Подтвердить код' : 'Далее';
        if (step === 'email') emailInput.focus();
        if (step === 'code') codeInput.focus();
        if (step === 'telegram') telegramLink.start();
        else telegramLink.stop();
    }

    async function post(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = Object.values(payload.errors || {}).flat().find(Boolean);
            const error = new Error(validation || payload.message || 'Не удалось выполнить запрос.');
            error.payload = payload;
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    const telegramLink = createTelegramContactBotLink({
        root, dialog, post,
        onSuccess: () => window.location.assign(root.dataset.confirmationUrl),
        onError: message => setError(message),
    });

    function openWizard(trigger) {
        opener = trigger;
        contactId = trigger.dataset.existingContact || null;
        emailInput.value = trigger.dataset.emailValue || '';
        codeInput.value = '';
        cooldown(0);
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[tabindex="-1"]')?.focus({ preventScroll: true });
        showStep(contactId ? 'code' : 'method');
        if (contactId) setFeedback('Если код уже отправлен, введи его. Иначе запроси новый.');
    }

    root.querySelectorAll('[data-contact-wizard-open]').forEach(trigger => {
        trigger.addEventListener('click', () => openWizard(trigger));
    });

    dialog.querySelectorAll('[data-wizard-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        cooldown(0);
        telegramLink.stop();
        opener?.focus();
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog && !isBusy) dialog.close();
    });

    backButton.addEventListener('click', () => {
        if (isBusy) return;
        if (step === 'code') showStep('email');
        else showStep('method');
    });

    nextButton.addEventListener('click', async () => {
        if (isBusy) return;
        if (step === 'method') {
            const method = dialog.querySelector('input[name="confirmation_channel"]:checked')?.value;
            if (!method) { setError('Выбери способ связи.'); return; }
            if (method === 'phone') { setError('SMS-подтверждение пока недоступно.'); return; }
            showStep(method);
            return;
        }
        if (step === 'email') {
            emailInput.value = emailInput.value.trim();
            if (!emailInput.reportValidity()) return;
            setBusy(true);
            setError();
            try {
                const response = await post(root.dataset.storeUrl, {
                    type: 'email', value: emailInput.value,
                });
                contactId = response.contact_id;
                showStep('code');
                cooldown(response.seconds_left || 60);
                setFeedback('Код отправлен на ' + (response.value || emailInput.value) + '. Он действует 15 минут.');
            } catch (error) {
                if (error.status === 429 && error.payload?.contact_id) {
                    contactId = error.payload.contact_id;
                    showStep('code');
                    cooldown(error.payload.seconds_left || 60);
                    setFeedback('Код уже был отправлен. Проверь почту.');
                } else {
                    setError(error.message);
                }
            } finally { setBusy(false); }
            return;
        }
        if (step === 'code') {
            const code = codeInput.value.trim();
            if (!/^[0-9]{6}$/.test(code)) {
                setError('Введи шестизначный код из письма.');
                return;
            }
            if (!contactId) {
                setError('Сначала запроси код подтверждения.');
                return;
            }
            setBusy(true);
            setError();
            try {
                const url = root.dataset.confirmUrl.replace('__ID__', encodeURIComponent(contactId));
                const response = await post(url, { code });
                setFeedback(response.message || 'Контакт подтверждён.');
                window.location.assign(response.redirect_url || window.location.href);
            } catch (error) { setError(error.message); }
            finally { setBusy(false); }
        }
    });

    resendButton.addEventListener('click', async () => {
        if (isBusy || remainingSeconds || !contactId) return;
        setBusy(true);
        setError();
        try {
            const url = root.dataset.resendUrl.replace('__ID__', encodeURIComponent(contactId));
            const response = await post(url, {});
            cooldown(response.seconds_left || 60);
            setFeedback('Новый код отправлен.');
        } catch (error) {
            if (error.status === 429) cooldown(error.payload?.seconds_left || 60);
            setError(error.message);
        } finally { setBusy(false); }
    });

}
