import { createContactProviderEmulation } from './contact-provider-emulation.js';
import { createTelegramContactBotLink } from './telegram-contact-bot-link.js';
import { refreshAccountContactsList } from './account-contacts-list.js';
import { contactWizardNavigation } from './contact-wizard-navigation.js';
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
    let methodLocked = false;
    let opener = null;
    let previousOverflow = '';
    let verificationContactId = null;
    let verificationEmail = '';
    const codeInput = dialog.querySelector('[data-contact-add-code]');
    const verifyButton = dialog.querySelector('[data-contact-add-verify]');
    const resendButton = dialog.querySelector('[data-contact-add-resend]');

    const setError = text => {
        error.textContent = text || '';
        error.hidden = !text;
        dialog.dataset.surfaceTone = text ? 'error' : (step === 'done' ? 'success' : 'neutral');
    };

    const setBusy = enabled => {
        busy = enabled;
        next.disabled = enabled;
        back.disabled = enabled;
        if (verifyButton) verifyButton.disabled = enabled;
        if (resendButton) resendButton.disabled = enabled;
        dialog.setAttribute('aria-busy', enabled ? 'true' : 'false');
    };

    function showStep(target) {
        step = target;
        setError('');
        steps.forEach(node => { node.hidden = node.dataset.contactAddStep !== target; });
        if (target === 'done') dialog.querySelector('.mskba-modal__body')?.scrollTo(0, 0);
        const navigation = contactWizardNavigation(target, methodLocked);
        footer.hidden = !navigation.footer;
        next.hidden = !navigation.next;
        back.hidden = !navigation.back;
        back.textContent = navigation.backLabel;
        next.textContent = target === 'value' ? 'Добавить контакт' : 'Далее';
        if (target === 'value') value.focus();
        if (target === 'verification') { if (codeInput) { codeInput.value=''; codeInput.focus(); } }
        if (target === 'telegram') telegramLink.start();
        else telegramLink.stop();
    }

    function open(preselected = 'email', skipMethod = false) {
        opener = document.activeElement;
        const radio=dialog.querySelector('input[name="new_contact_channel"][value="'+preselected+'"]');
        (radio && !radio.disabled ? radio : dialog.querySelector('input[name="new_contact_channel"][value="email"]')).checked = true;
        value.value = '';
        verificationContactId = null;
        verificationEmail = '';
        kind = (radio && !radio.disabled) ? preselected : 'email';
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[tabindex="-1"]')?.focus({ preventScroll: true });
        emulator.reset();
        methodLocked = Boolean(skipMethod && radio && !radio.disabled);
        // From a + button the contact kind is already known. Do not ask the
        // user to choose it again. Keep the selection screen for normal entry.
        if (methodLocked) advanceFromMethod();
        else showStep('method');
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
    const verificationUrl = () => '/account/contacts/'+verificationContactId+'/verification';
    async function sendEmailCode() {
        if (!verificationContactId) return;
        setBusy(true);setError('');
        try { await post(verificationUrl(), {}); }
        catch(e) { setError(e.message || 'Не удалось отправить код. Повтори запрос.'); }
        finally { setBusy(false); }
    }
    resendButton?.addEventListener('click',()=>void sendEmailCode());
    verifyButton?.addEventListener('click',async()=>{
        if (busy||!verificationContactId) return;
        const code=codeInput.value.trim();
        if (!/^\d{6}$/.test(code)) {setError('Введи шестизначный код подтверждения.');return;}
        setBusy(true);setError('');
        try {
            await post(verificationUrl()+'/confirm',{code});
            let updated=true;
            try { await refreshList(); } catch { updated=false; }
            success.textContent='Email подтверждён и готов к уведомлениям.'+
                (updated?'':' Контакты обновятся после перезагрузки страницы.');
            window.dispatchEvent(new CustomEvent('mskba:contact:updated'));
            showStep('done');
        }catch(e){setError(e.message || 'Код не подошёл. Попробуй ещё раз.');}
        finally {setBusy(false);}
    });

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
            window.dispatchEvent(new CustomEvent('mskba:contact:updated'));
            showStep('done');
        },
    });

    function advanceFromMethod() {
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
    }

    root.querySelector('[data-contact-add-open]')?.addEventListener('click', () => open());
    window.addEventListener('mskba:contact:add', event => {
        const type = event.detail?.type;
        open(type || 'email', Boolean(type));
    });
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
    back.addEventListener('click', () => {
        if (busy) return;
        const target = contactWizardNavigation(step, methodLocked).backTarget;
        if (target === 'close') dialog.close();
        else if (target) showStep(target);
    });

    next.addEventListener('click', async () => {
        if (busy) return;
        if (step === 'method') {
            advanceFromMethod();
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
        // Returning from verification with the same email must not try to
        // create the already-pending contact a second time.
        if (kind === 'email' && verificationContactId &&
            value.value.toLowerCase() === verificationEmail) {
            showStep('verification');
            return;
        }
        setBusy(true);
        setError('');
        try {
            const result = await post(root.dataset.addUrl, { type: kind, value: value.value });
            let updated = true;
            try { await refreshList(); } catch { updated = false; }
            if (kind==='email' && result.contact?.id && !result.contact.is_verified) {
                verificationContactId=Number(result.contact.id);
                verificationEmail=value.value.toLowerCase();
                showStep('verification');
                await sendEmailCode();
            } else {
                success.textContent = (result.contact?.value || value.value)
                    + ' — контакт добавлен. ' + (kind==='phone'
                        ? 'SMS-подтверждение пока недоступно.'
                        : updated?'':' Обнови страницу, чтобы увидеть контакт.');
                window.dispatchEvent(new CustomEvent('mskba:contact:updated'));
                showStep('done');
            }
        } catch (exception) {
            // Addition may already have succeeded even if the list-refresh request failed.
            if (exception instanceof Error) setError(exception.message);
        } finally {
            setBusy(false);
        }
    });

}
