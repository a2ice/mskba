const root = document.querySelector('[data-contacts-add]');

if (root) {
    const dialog = root.querySelector('[data-contact-add-modal]');
    const steps = [...dialog.querySelectorAll('[data-contact-add-step]')];
    const error = dialog.querySelector('[data-contact-add-error]');
    const value = dialog.querySelector('[data-contact-add-value]');
    const label = dialog.querySelector('[data-contact-add-value-label]');
    const title = dialog.querySelector('[data-contact-add-value-title]');
    const description = dialog.querySelector('[data-contact-add-value-description]');
    const success = dialog.querySelector('[data-contact-add-success]');
    const back = dialog.querySelector('[data-contact-add-back]');
    const next = dialog.querySelector('[data-contact-add-next]');
    const footer = dialog.querySelector('[data-contact-add-footer]');
    const telegram = dialog.querySelector('[data-contact-add-telegram]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let step = 'method';
    let kind = 'email';
    let busy = false;
    let opener = null;

    const setError = text => {
        error.textContent = text || '';
        error.hidden = !text;
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
        footer.hidden = !['method', 'value'].includes(target);
        back.hidden = target === 'method';
        next.textContent = target === 'value' ? 'Добавить контакт' : 'Далее';
        if (target === 'value') value.focus();
        if (target === 'telegram') mountTelegram();
    }

    function open() {
        opener = document.activeElement;
        dialog.querySelector('input[name="new_contact_channel"][value="email"]').checked = true;
        value.value = '';
        kind = 'email';
        dialog.showModal();
        showStep('method');
    }

    function configureValueField() {
        const isEmail = kind === 'email';
        title.textContent = isEmail ? 'Укажи Email' : 'Укажи телефон';
        description.textContent = isEmail
            ? 'Адрес будет проверен на сервере. Код подтверждения можно отправить после добавления.'
            : 'Телефон добавится без подтверждения. SMS-подтверждение появится позже.';
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
            throw exception;
        }
        return data;
    }

    async function refreshList() {
        const response = await fetch(root.dataset.listUrl, {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('Не удалось обновить список контактов.');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updated = page.querySelector('[data-account-contacts-list]');
        const current = document.querySelector('[data-account-contacts-list]');
        if (!updated || !current) throw new Error('Не удалось получить обновлённый список контактов.');
        current.replaceWith(updated);
    }

    function mountTelegram() {
        if (telegram.dataset.mounted === 'true') return;
        const username = root.dataset.telegramBot;
        if (!username) { setError('Подключение Telegram сейчас недоступно.'); return; }
        telegram.dataset.mounted = 'true';
        const widget = document.createElement('script');
        widget.async = true;
        widget.src = 'https://telegram.org/js/telegram-widget.js?22';
        widget.dataset.telegramLogin = username;
        widget.dataset.size = 'large';
        widget.dataset.radius = '10';
        widget.dataset.userpic = 'false';
        widget.dataset.onauth = 'mskbaContactsTelegram(user)';
        telegram.appendChild(widget);
    }

    root.querySelector('[data-contact-add-open]').addEventListener('click', open);
    dialog.querySelectorAll('[data-contact-add-close]').forEach(button => {
        button.addEventListener('click', () => { if (!busy) dialog.close(); });
    });
    dialog.addEventListener('close', () => opener?.focus());
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
            await refreshList();
            success.textContent = (result.contact?.value || value.value)
                + ' — контакт добавлен. Подтвердить его можно в списке контактов.';
            showStep('done');
        } catch (exception) {
            // Addition may already have succeeded even if the list-refresh request failed.
            if (exception instanceof Error) setError(exception.message);
        } finally {
            setBusy(false);
        }
    });

    window.mskbaContactsTelegram = async telegramUser => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await post(root.dataset.telegramUrl, { telegram_user: telegramUser });
            await refreshList();
            success.textContent = result.message || 'Telegram подтверждён и добавлен в список.';
            showStep('done');
        } catch (exception) {
            if (exception.data?.status === 'duplicate' && exception.data?.redirect_url) {
                window.location.assign(exception.data.redirect_url);
                return;
            }
            setError(exception.message || 'Не удалось подключить Telegram.');
        } finally { setBusy(false); }
    };
}
