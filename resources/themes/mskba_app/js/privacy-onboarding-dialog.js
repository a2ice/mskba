import { initPrivacyDistribution, initNotificationPreview } from './privacy-distribution.js';

const root = document.querySelector('[data-onboarding-pending]');
if (root) {
    const originalFetch = window.fetch.bind(window);
    const setupUrl = root.dataset.onboardingUrl;
    const saveUrl = root.dataset.onboardingSave;
    const accountUrl = root.dataset.accountUrl;
    const logoutPath = new URL(root.dataset.logoutUrl, location.origin).pathname;
    let done = false;
    let dialog;
    let body;
    let form;
    let currentError;
    let previousOverflow = '';
    let focusTarget = null;
    let pending = null;
    let busy = false;
    let loading = null;

    const isSetup = url => {
        const path = new URL(url, location.href).pathname;
        return path === new URL(setupUrl, location.href).pathname
            || path === new URL(saveUrl, location.href).pathname;
    };

    function ensureDialog() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.className = 'mskba-modal mskba-onboarding-dialog';
        dialog.setAttribute('aria-labelledby', 'mskba-onboarding-dialog-title');
        dialog.innerHTML =
            '<header class="mskba-modal__header mskba-onboarding-dialog__header">' +
            '  <div><p class="eyebrow accent">MSKBA / ПОСЛЕДНИЙ ШАГ</p>' +
            '  <h2 id="mskba-onboarding-dialog-title" tabindex="-1">Завершите регистрацию</h2></div>' +
            '  <button type="button" class="icon-button" data-onboarding-close aria-label="Закрыть настройку">' +
            '    <svg aria-hidden="true"><use href="#close"/></svg></button>' +
            '</header>' +
            '<div class="mskba-modal__body mskba-scroll mskba-onboarding-dialog__body"' +
            ' role="region" aria-label="Настройки приватности и уведомлений" tabindex="0">' +
            '  <p role="status">Загружаем настройки…</p></div>' +
            '<footer class="mskba-modal__footer mskba-onboarding-dialog__footer"></footer>';
        document.body.append(dialog);
        body = dialog.querySelector('.mskba-onboarding-dialog__body');
        dialog.querySelectorAll('[data-onboarding-close]').forEach(b => b.addEventListener('click', closeDialog));
        dialog.addEventListener('cancel', e => { e.preventDefault(); closeDialog(); });
        dialog.addEventListener('click', e => {
            if (e.target !== dialog) return;
            const b = dialog.getBoundingClientRect();
            if (e.clientX < b.left || e.clientX > b.right || e.clientY < b.top || e.clientY > b.bottom) closeDialog();
        });
        dialog.addEventListener('submit', saveSettings);
    }

    function showError(message) {
        if (!currentError) {
            currentError = document.createElement('p');
            currentError.className = 'mskba-onboarding-dialog__error';
            currentError.setAttribute('role', 'alert');
            currentError.tabIndex = -1;
            body.prepend(currentError);
        }
        currentError.textContent = message;
        currentError.hidden = false;
    }

    async function loadForm() {
        if (loading) return loading;
        loading = (async () => {
            try {
                const url = new URL(setupUrl, location.origin);
                url.searchParams.set('modal', '1');
                const response = await originalFetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) {
                    throw new Error('Privacy form unavailable');
                }
                const html = await response.text();
                if (done || !dialog?.open) return;
                body.innerHTML = html;
                form = body.querySelector('form[data-privacy-distribution]');
                if (!form) throw new Error('Privacy form missing');
                currentError = null;
                // Keep form actions fixed in the modal footer while the long
                // privacy form scrolls independently in the body.
                form.id = 'mskba-onboarding-settings-form';
                const actions = form.querySelector('.privacy-onboarding__actions');
                if (actions) {
                    actions.querySelectorAll('button[type="submit"]').forEach(button => {
                        button.setAttribute('form', form.id);
                    });
                    dialog.querySelector('.mskba-onboarding-dialog__footer').prepend(actions);
                }
                initPrivacyDistribution(form);
                initNotificationPreview(body.querySelector('[data-onboarding-notification-preview]'));
            } catch {
                showError('Не удалось загрузить настройки. Повторите попытку или откройте отдельную страницу.');
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button secondary';
                retry.textContent = 'Повторить';
                retry.addEventListener('click', () => {
                    body.innerHTML = '<p role="status">Загружаем настройки…</p>';
                    currentError = null;
                    loading = null;
                    loadForm();
                });
                const fallback = document.createElement('a');
                fallback.href = setupUrl;
                fallback.className = 'mskba-onboarding-dialog__fallback';
                fallback.textContent = 'Открыть отдельную страницу приватности';
                body.append(retry, fallback);
            } finally {
                loading = null;
            }
        })();
        return loading;
    }

    function openDialog(trigger = document.activeElement) {
        if (done) return;
        ensureDialog();
        focusTarget = trigger;
        if (dialog.open) return;
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('#mskba-onboarding-dialog-title')?.focus({ preventScroll: true });
        if (!form) loadForm();
    }

    function closeDialog() {
        if (!dialog?.open || busy) return;
        dialog.close();
        document.body.style.overflow = previousOverflow;
        const queued = pending;
        pending = null;
        queued?.reject?.(new DOMException('Регистрация не завершена', 'AbortError'));
        if (focusTarget?.isConnected) focusTarget.focus?.({ preventScroll: true });
        focusTarget = null;
    }

    function queueAction(resume, reject, trigger) {
        if (done) return resume();
        // Never silently accumulate multiple state-changing operations.
        const replaced = pending;
        pending = { resume, reject };
        replaced?.reject?.(new DOMException('Операция заменена', 'AbortError'));
        openDialog(trigger);
    }

    async function saveSettings(event) {
        if (!event.target.matches('form[data-privacy-distribution]')) return;
        event.preventDefault();
        event.stopPropagation();
        if (busy) return;
        const payload = new FormData(event.target);
        payload.set('action', event.submitter?.value === 'private' ? 'private' : 'save');
        busy = true;
        dialog.querySelectorAll('button').forEach(btn => btn.disabled = true);
        try {
            const response = await originalFetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: payload,
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const issues = Object.values(result.errors || {}).flat().filter(Boolean);
                showError(issues.join(' ') || result.message || 'Не удалось сохранить настройки.');
                currentError.focus({ preventScroll: true });
                body.scrollTo({ top: 0 });
                return;
            }
            if (result.completed !== true) throw new Error('Not confirmed by server');
            done = true;
            dialog.close();
            document.body.style.overflow = previousOverflow;
            root.remove();
            document.querySelectorAll('.app-header-account--setup-pending').forEach(node => {
                node.classList.remove('app-header-account--setup-pending');
                node.removeAttribute('data-open-onboarding');
                node.setAttribute('href', accountUrl);
                node.setAttribute('aria-label', 'Личный кабинет');
            });
            const queued = pending;
            pending = null;
            if (queued) queued.resume();
            else location.reload();
        } catch {
            showError('Не удалось сохранить настройки. Проверьте соединение и попробуйте ещё раз.');
        } finally {
            busy = false;
            if (!done && dialog?.open) dialog.querySelectorAll('button').forEach(btn => btn.disabled = false);
        }
    }

    // Defer classic HTML mutations. The original form remains intact and is
    // submitted once AFTER server confirmation, never while setup is pending.
    document.addEventListener('submit', event => {
        if (done || event.target.closest('.mskba-onboarding-dialog')) return;
        const submitted = event.target;
        if (!(submitted instanceof HTMLFormElement)) return;
        const method = (submitted.querySelector('input[name="_method"]')?.value
            || submitted.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'GET' || method === 'HEAD') return;
        const url = new URL(submitted.action, location.href);
        if (url.origin !== location.origin || isSetup(url.href) || url.pathname === logoutPath) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        queueAction(() => {
            if (submitter?.isConnected) submitted.requestSubmit(submitter);
            else submitted.requestSubmit();
        }, null, submitter || submitted);
    }, true);

    // Intercept same-origin fetch mutations and replay only the one queued
    // request after the account is confirmed. Closed dialogs cancel it.
    window.fetch = function (input, init) {
        const request = new Request(input, init);
        const method = request.method.toUpperCase();
        const url = new URL(request.url);
        if (done || ['GET', 'HEAD', 'OPTIONS'].includes(method)
            || url.origin !== location.origin || isSetup(url.href)
            || url.pathname === logoutPath) return originalFetch(input, init);
        const saved = request.clone();
        return new Promise((resolve, reject) => {
            queueAction(() => originalFetch(saved).then(resolve, reject), reject, document.activeElement);
        });
    };

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-open-onboarding]');
        if (!trigger || done) return;
        event.preventDefault();
        openDialog(trigger);
    });
    window.addEventListener('mskba:onboarding-required', () => openDialog());

    // The onboarding reminder belongs to this page lifecycle, not sessionStorage:
    // duplicated tabs may inherit storage and silently suppress the required dialog.
    // Closing affects only this open document; each new account-page visit
    // (except the standalone privacy form) offers setup while still required.
    if (root.dataset.autoShow === '1' || root.dataset.forceShow === '1') openDialog();
}
