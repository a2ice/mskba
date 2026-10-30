/* MSKBA 063: user-funded actions stay separate from wallet mutations. */
const topup = document.querySelector('[data-player-topup-dialog]');
if (topup) {
    const tabs = Array.from(topup.querySelectorAll('[data-topup-tab]'));
    const panels = Array.from(topup.querySelectorAll('[data-topup-panel]'));
    const setTab = key => {
        tabs.forEach(tab => {
            const active = tab.dataset.topupTab === key;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.topupPanel !== key; });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => setTab(tab.dataset.topupTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const nextIndex = event.key === 'Home' ? 0 : event.key === 'End'
                ? tabs.length - 1
                : (index + (event.key === 'ArrowLeft' ? -1 : 1) + tabs.length) % tabs.length;
            setTab(tabs[nextIndex].dataset.topupTab);
            tabs[nextIndex].focus();
        });
    });
    topup.querySelectorAll('[data-topup-task]').forEach(details => {
        details.addEventListener('toggle', () => {
            if (details.open) topup.querySelectorAll('[data-topup-task]').forEach(other => {
                if (other !== details) other.open = false;
            });
        });
    });

    const subdialogs = {
        venue: document.querySelector('[data-player-venue-dialog]'),
        referral: document.querySelector('[data-player-referral-dialog]'),
    };
    topup.querySelectorAll('[data-topup-open-dialog]').forEach(button => {
        button.addEventListener('click', () => {
            const dialog = subdialogs[button.dataset.topupOpenDialog];
            if (dialog?.showModal && !dialog.open) dialog.showModal();
        });
    });
    Object.values(subdialogs).forEach(dialog => {
        dialog?.querySelectorAll('[data-topup-subdialog-close]').forEach(button =>
            button.addEventListener('click', () => dialog.close()));
        dialog?.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
    });

    const referral = subdialogs.referral;
    const link = referral?.querySelector('[data-referral-url]');
    const feedback = referral?.querySelector('[data-referral-status]');
    const referralMessage = 'Присоединяйся к баскетбольному сообществу MSKBA';
    referral?.querySelector('[data-referral-copy]')?.addEventListener('click', async () => {
        if (!link) return;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(link.value);
            } else {
                link.select();
                if (!document.execCommand('copy')) throw new Error('Copy unavailable');
                link.setSelectionRange(0, 0);
            }
            if (feedback) feedback.textContent = 'Реферальная ссылка скопирована.';
        } catch {
            if (feedback) feedback.textContent = 'Не удалось скопировать автоматически. Выделите ссылку и скопируйте её.';
        }
    });
    referral?.querySelector('[data-referral-share]')?.addEventListener('click', async () => {
        if (!link) return;
        try {
            if (navigator.share) {
                await navigator.share({title: 'MSKBA', text: referralMessage, url: link.value});
            } else {
                const body = [referralMessage, link.value].join('\n');
                window.location.href = 'mailto:?subject=' + encodeURIComponent('Присоединяйся к MSKBA') +
                    '&body=' + encodeURIComponent(body);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && feedback) {
                feedback.textContent = 'Не удалось открыть отправку. Скопируйте ссылку вручную.';
            }
        }
    });
}

/* Search never pre-authorizes an action; every recipient is checked again on POST. */
function initRecipientSearch(form, options) {
    const input = form.querySelector(options.query);
    const results = form.querySelector(options.results);
    const selected = form.querySelector(options.selected);
    const button = form.querySelector(options.submit);
    const status = form.querySelector(options.status);
    if (!input || !results || !selected || !button) return;
    let debounce = null;
    let controller = null;

    const clear = () => {
        selected.value = '';
        button.disabled = true;
        results.replaceChildren();
        results.hidden = true;
    };
    const show = candidates => {
        results.replaceChildren();
        if (!candidates.length) {
            if (status) status.textContent = 'Подходящих пользователей не найдено.';
            return;
        }
        candidates.forEach(candidate => {
            const choice = document.createElement('button');
            choice.type = 'button';
            choice.role = 'option';
            choice.className = 'app-player-workspace__transfer-choice';
            choice.textContent = candidate.name + (candidate.meta ? ' · ' + candidate.meta : '');
            choice.addEventListener('click', () => {
                input.value = choice.textContent;
                selected.value = String(candidate.id);
                button.disabled = false;
                results.replaceChildren();
                results.hidden = true;
                if (status) status.textContent = 'Получатель выбран.';
            });
            results.appendChild(choice);
        });
        results.hidden = false;
    };
    input.addEventListener('input', () => {
        clearTimeout(debounce);
        controller?.abort();
        clear();
        const query = input.value.trim();
        if (status) status.textContent = query.length < 2 ? 'Введите не менее двух символов.' : 'Ищем пользователей…';
        if (query.length < 2) return;
        debounce = setTimeout(async () => {
            controller = new AbortController();
            try {
                const url = new URL(form.dataset.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('purpose', options.purpose);
                const response = await fetch(url, {
                    signal: controller.signal, credentials: 'same-origin',
                    headers: {Accept:'application/json'},
                });
                if (!response.ok) throw new Error('Search failed');
                const payload = await response.json();
                if (input.value.trim() !== query) return;
                if (status) status.textContent = '';
                show(Array.isArray(payload.candidates) ? payload.candidates : []);
            } catch (error) {
                if (error.name !== 'AbortError' && status) status.textContent = 'Поиск недоступен. Попробуйте ещё раз.';
            }
        }, 300);
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !results.hidden) {
            results.hidden = true;
            event.stopPropagation();
        }
    });
    if (options.request) {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!selected.value || button.disabled) return;
            button.disabled = true;
            if (status) status.textContent = 'Отправляем запрос…';
            try {
                const response = await fetch(form.action, {
                    method:'POST', credentials:'same-origin',
                    headers:{
                        Accept:'application/json',
                        'Content-Type':'application/json',
                        'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({recipient_user_id: Number(selected.value)}),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'Не удалось отправить запрос.');
                if (status) status.textContent = payload.message || 'Запрос отправлен.';
                clear();
                input.value = '';
            } catch (error) {
                if (status) status.textContent = error.message || 'Не удалось отправить запрос.';
                button.disabled = !selected.value;
            }
        });
    } else {
        form.addEventListener('submit', event => {
            if (!selected.value) {
                event.preventDefault();
                if (status) status.textContent = 'Выберите получателя из подсказок.';
            }
        });
    }
}

document.querySelectorAll('[data-player-transfer-request-form]').forEach(form =>
    initRecipientSearch(form, {
        query:'[data-transfer-query]', results:'[data-transfer-results]',
        selected:'[data-transfer-selected]', submit:'[data-transfer-submit]',
        status:'[data-transfer-status]', purpose:'request', request:true,
    }));
document.querySelectorAll('[data-wallet-transfer-form]').forEach(form =>
    initRecipientSearch(form, {
        query:'[data-wallet-recipient-query]', results:'[data-wallet-recipient-results]',
        selected:'[data-wallet-recipient-id]', submit:'[data-wallet-transfer-submit]',
        status:'[data-wallet-transfer-status]', purpose:'transfer', request:false,
    }));

/* The same form is instantiated in the dialog and at /venues/create. */
document.querySelectorAll('[data-mskba-venue-create]').forEach(form => {
    form.querySelector('[data-venue-address-manual]')?.addEventListener('click', () => {
        form.querySelector('[data-address-suggest-input]')?.focus();
    });
    const button = form.querySelector('[data-venue-submit]');
    const status = form.querySelector('[data-venue-create-status]');
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        if (!form.querySelector('[data-address-selected]')?.value) {
            if (status) status.textContent = 'Выберите точный адрес из подсказок или воспользуйтесь геолокацией.';
            return;
        }
        button.disabled = true;
        if (status) status.textContent = 'Добавляем площадку…';
        try {
            const response = await fetch(form.action, {
                method:'POST', credentials:'same-origin',
                headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'},
                body:new FormData(form),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const messages = Object.values(payload.errors || {}).flat();
                throw new Error(messages.slice(0, 3).join(' ') || payload.message || 'Не удалось сохранить площадку.');
            }
            if (status) status.textContent = payload.warning || payload.message || 'Площадка добавлена.';
            window.location.assign(payload.management_url || form.dataset.successUrl);
        } catch (error) {
            if (status) status.textContent = error.message || 'Не удалось сохранить площадку.';
            button.disabled = false;
        }
    });
});
