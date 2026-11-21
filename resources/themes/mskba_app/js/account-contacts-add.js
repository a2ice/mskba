import { createContactProviderEmulation } from './contact-provider-emulation.js';
import { createTelegramContactBotLink } from './telegram-contact-bot-link.js';
import { refreshAccountContactsList } from './account-contacts-list.js';
import { createContactResendCooldown } from './contact-resend-cooldown.js';
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
    const changeButton = dialog.querySelector('[data-contact-add-change]');
    const changeDialog = root.querySelector('[data-contact-change-modal]');
    const pendingCooldowns = Object.fromEntries(Object.entries(JSON.parse(root.dataset.pendingCooldowns || '{}')).map(([id, seconds]) => [id, Date.now() + Number(seconds) * 1000]));
    let changeMode = null;
    let changedFromValue = false;
    const cooldown = createContactResendCooldown(seconds => {
        resendButton.disabled = busy || seconds > 0;
        resendButton.textContent = seconds > 0 ? 'Отправить код повторно (' + seconds + ' с)' : 'Отправить код повторно';
    });

    function setContactCooldown(seconds) {
        pendingCooldowns[verificationContactId] = Date.now() + Number(seconds || 0) * 1000;
        cooldown.set(seconds);
    }

    function openChange(fromValue = false) {
        if (busy || !verificationContactId || changeDialog.open) return;
        changedFromValue = fromValue;
        changeDialog.querySelector('[data-contact-change-value]').textContent = 'Email: ' + verificationEmail;
        changeDialog.showModal();
    }
    changeButton.addEventListener('click', () => openChange());
    changeDialog.querySelector('[data-contact-change-close]').addEventListener('click', () => changeDialog.close());
    changeDialog.querySelector('[data-contact-change-replace]').addEventListener('click', () => {
        changeMode = 'replace';
        changeDialog.close();
        showStep('value');
        if (!changedFromValue) value.select();
    });
    changeDialog.querySelector('[data-contact-change-add]').addEventListener('click', () => {
        changeMode = 'add';
        changeDialog.close();
        if (changedFromValue) {
            showStep('value');
        } else {
            verificationContactId = null;
            verificationEmail = '';
            methodLocked = false;
            cooldown.set(0);
            showStep('method');
        }
    });

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
        if (resendButton) resendButton.disabled = enabled || cooldown.secondsLeft > 0;
        changeButton.disabled = enabled;
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
        verifyButton.hidden = target !== 'verification';
        footer.classList.toggle('app-contact-wizard__verification-footer', target === 'verification');
        back.hidden = !navigation.back;
        back.textContent = navigation.backLabel;
        next.textContent = target === 'value' ? (changeMode === 'replace' ? 'Заменить контакт' : 'Добавить контакт') : 'Далее';
        if (target === 'value') value.focus();
        if (target === 'verification') {
            const address = dialog.querySelector('[data-contact-add-verification-email]');
            if (address) address.textContent = verificationEmail;
            if (codeInput) { codeInput.value=''; codeInput.focus(); }
        }
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
        changeMode = null;
        cooldown.set(0);
        kind = (radio && !radio.disabled) ? preselected : 'email';
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[data-contact-add-close]')?.focus({ preventScroll: true });
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
        if (!verificationContactId || cooldown.secondsLeft > 0) return;
        setBusy(true);setError('');
        try {
            const result = await post(verificationUrl(), {});
            setContactCooldown(result.seconds_left || 0);
        }
        catch(e) {
            if (e.status === 429) setContactCooldown(e.data?.seconds_left || 0);
            setError(e.message || 'Не удалось отправить код. Повтори запрос.');
        }
        finally { setBusy(false); }
    }
    resendButton?.addEventListener('click',()=>{ if (!busy) void sendEmailCode(); });
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
        const resume = event.detail?.resume;
        if (resume && Number.isSafeInteger(Number(resume.id)) && Number(resume.id) > 0 && typeof resume.value === 'string') {
            open('email', true);
            verificationContactId = Number(resume.id);
            verificationEmail = resume.value.toLowerCase();
            value.value = resume.value;
            cooldown.set(Math.max(0, Math.ceil(((pendingCooldowns[verificationContactId] || 0) - Date.now()) / 1000)));
            showStep('verification');
            return;
        }
        open(type || 'email', Boolean(type));
    });
    dialog.querySelectorAll('[data-contact-add-close]').forEach(button => {
        button.addEventListener('click', () => { if (!busy) dialog.close(); });
    });
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        telegramLink.stop();
        cooldown.dispose();
        opener?.focus();
    });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('click', event => { if (event.target === dialog && !busy) dialog.close(); });

    dialog.querySelector('[data-contact-add-again]').addEventListener('click', () => {
        methodLocked = false;
        verificationContactId = null;
        verificationEmail = '';
        changeMode = null;
        cooldown.set(0);
        showStep('method');
        value.value = '';
    });
    back.addEventListener('click', () => {
        if (busy) return;
        const target = contactWizardNavigation(step, methodLocked).backTarget;
        if (step === 'value' && verificationContactId) {
            changeMode = null;
            value.value = verificationEmail;
            showStep('verification');
        } else if (target === 'close') dialog.close();
        else if (target) {
            if (step === 'verification') {
                value.value = verificationEmail;
                changeMode = null;
            }
            showStep(target);
        }
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
            changeMode = null;
            showStep('verification');
            return;
        }
        if (kind === 'email' && verificationContactId && !changeMode) {
            openChange(true);
            return;
        }
        setBusy(true);
        setError('');
        try {
            const replacing = changeMode === 'replace' && verificationContactId;
            const result = await post(root.dataset.addUrl, {
                type: kind, value: value.value,
                ...(replacing ? { replace_contact_id: verificationContactId } : {}),
            });
            changeMode = null;
            let updated = true;
            try { await refreshList(); } catch { updated = false; }
            if (kind==='email' && result.contact?.id && !result.contact.is_verified) {
                verificationContactId=Number(result.contact.id);
                verificationEmail=value.value.toLowerCase();
                showStep('verification');
                window.dispatchEvent(new CustomEvent('mskba:contact:pending',{detail:{id:verificationContactId,value:verificationEmail}}));
                setContactCooldown(result.seconds_left || 0);
                if (!replacing) await sendEmailCode();
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
