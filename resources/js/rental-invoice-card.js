import '../css/rental-invoice-card.css';

const create = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
};

export function renderRentalInvoiceCard(data) {
    if (!data) return null;
    const card = create('section', 'app-rental-invoice-card');
    card.dataset.rentalInvoiceCard = '1';
    card.append(create('strong', '', data.title + ' · ' + data.number));
    card.append(create('div', 'app-rental-invoice-card__amount', data.amount + ' ' + data.currency));
    card.append(create('p', '', data.status_label + ' · До ' + data.due_at));
    card.append(create('p', '', 'Способ оплаты: ' + (data.methods || []).join(', ')));
    if (data.instructions) card.append(create('p', '', data.instructions));
    const details = data.details || {};
    for (const [key, label] of [['bank', 'Банк'], ['phone', 'Телефон'], ['card', 'Номер карты'], ['purpose', 'Комментарий к переводу']]) {
        if (details[key]) card.append(create('p', '', label + ': ' + details[key]));
    }
    if (data.reference) card.append(create('p', '', 'Номер операции: ' + data.reference));
    if (data.claim_note) card.append(create('p', '', 'Комментарий плательщика: ' + data.claim_note));
    const actions = create('div', 'app-rental-invoice-card__actions');
    if (data.copy_text) {
        const button = create('button', 'button secondary', 'Скопировать реквизиты');
        button.type = 'button';
        button.dataset.rentalInvoiceCopy = data.copy_text;
        actions.append(button);
    }
    if (data.claim_url) {
        const button = create('button', 'button primary', 'Я оплатил');
        button.type = 'button';
        button.dataset.rentalInvoiceClaim = '1';
        button.dataset.url = data.claim_url;
        button.dataset.referenceRequired = data.reference_required ? '1' : '0';
        button.dataset.confirmationHint = data.confirmation_hint || '';
        actions.append(button);
    }
    if (data.settle_url) {
        const button = create('button', 'button primary btn btn--primary btn--sm', 'Подтвердить получение');
        button.type = 'button';
        button.dataset.rentalInvoiceSettle = '1';
        button.dataset.url = data.settle_url;
        button.dataset.method = data.settle_method || 'other';
        actions.append(button);
    }
    const link = create('a', 'button secondary', 'Открыть счёт');
    link.href = data.booking_url;
    actions.append(link);
    card.append(actions);
    return card;
}

export function bindRentalInvoiceActions(root = document) {
    root.addEventListener('click', async event => {
        const copy = event.target.closest('[data-rental-invoice-copy]');
        if (copy && root.contains(copy)) {
            try {
                await navigator.clipboard.writeText(copy.dataset.rentalInvoiceCopy);
                copy.textContent = 'Скопировано';
            } catch (_error) {
                copy.textContent = 'Не удалось скопировать';
            }
            return;
        }
        const settle = event.target.closest('[data-rental-invoice-settle]');
        if (settle && root.contains(settle)) {
            const dialog = create('dialog', 'app-rental-invoice-claim-dialog');
            const form = create('form');
            const heading = create('h3', '', 'Подтверждение получения оплаты');
            const warning = create('p', '', 'Подтверждайте только фактически полученные средства. Заявление плательщика само по себе не является подтверждением.');
            const label = create('label', '', 'Основание подтверждения');
            const note = create('textarea');
            note.name = 'note';
            note.maxLength = 2000;
            note.rows = 3;
            note.required = true;
            label.append(note);
            const error = create('p', 'app-rental-invoice-claim-dialog__error');
            error.hidden = true;
            const actions = create('div', 'app-rental-invoice-claim-dialog__actions');
            const back = create('button', 'button secondary btn btn--secondary', 'Назад');
            back.type = 'button';
            back.addEventListener('click', () => dialog.close());
            const submit = create('button', 'button primary btn btn--primary', 'Подтвердить');
            submit.type = 'submit';
            actions.append(back, submit);
            form.append(heading, warning, label, error, actions);
            dialog.append(form);
            document.body.append(dialog);
            dialog.addEventListener('close', () => dialog.remove(), {once:true});
            form.addEventListener('submit', async e => {
                e.preventDefault();
                submit.disabled = true;
                error.hidden = true;
                try {
                    const response = await fetch(settle.dataset.url, {
                        method:'POST', credentials:'same-origin',
                        headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept':'application/json', 'Content-Type':'application/json'},
                        body: JSON.stringify({decision:'received', method:settle.dataset.method, note:note.value.trim()})
                    });
                    const result = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'Не удалось подтвердить оплату.');
                    }
                    dialog.close();
                    window.location.reload();
                } catch (err) {
                    error.textContent = err.message || 'Не удалось подтвердить оплату.';
                    error.hidden = false;
                    submit.disabled = false;
                }
            });
            dialog.showModal();
            note.focus();
            return;
        }
        const button = event.target.closest('[data-rental-invoice-claim]');
        if (!button || !root.contains(button)) return;
        const dialog = create('dialog', 'app-rental-invoice-claim-dialog');
        const form = create('form');
        const heading = create('h3', '', 'Сообщить об оплате');
        const hint = create('p', '', button.dataset.confirmationHint || 'Укажите номер операции из банковского приложения. Деньги считаются полученными только после проверки.');
        const refLabel = create('label', '', 'Номер платежного поручения или операции');
        const reference = create('input');
        reference.name = 'reference';
        reference.maxLength = 190;
        reference.required = button.dataset.referenceRequired === '1';
        reference.autocomplete = 'off';
        refLabel.append(reference);
        const noteLabel = create('label', '', 'Комментарий (необязательно)');
        const note = create('textarea');
        note.name = 'note';
        note.rows = 3;
        note.maxLength = 2000;
        noteLabel.append(note);
        const error = create('p', 'app-rental-invoice-claim-dialog__error');
        error.hidden = true;
        const actions = create('div', 'app-rental-invoice-claim-dialog__actions');
        const cancel = create('button', 'button secondary', 'Назад');
        cancel.type = 'button';
        cancel.addEventListener('click', () => dialog.close());
        const submit = create('button', 'button primary', 'Сообщить об оплате');
        submit.type = 'submit';
        actions.append(cancel, submit);
        form.append(heading, hint, refLabel, noteLabel, error, actions);
        dialog.append(form);
        document.body.append(dialog);
        dialog.addEventListener('close', () => dialog.remove(), {once:true});
        form.addEventListener('submit', async e => {
            e.preventDefault();
            submit.disabled = true;
            error.hidden = true;
            try {
                const response = await fetch(button.dataset.url, {
                    method:'POST', credentials:'same-origin',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept':'application/json', 'Content-Type':'application/json'},
                    body:JSON.stringify({reference: reference.value.trim(), note: note.value.trim()})
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'Не удалось отправить подтверждение.');
                }
                dialog.close();
                window.location.reload();
            } catch (err) {
                error.textContent = err.message || 'Не удалось отправить подтверждение.';
                error.hidden = false;
                submit.disabled = false;
            }
        });
        dialog.showModal();
        reference.focus();
    });
}

export function bindInvoiceIssuanceFields(root = document) {
    root.querySelectorAll('[data-invoice-issue-form]').forEach(form => {
        const method = form.querySelector('[data-invoice-method]');
        const fields = form.querySelector('[data-invoice-sbp-fields]');
        const profiles = form.querySelector('[data-invoice-profile]');
        const fresh = form.querySelector('[data-invoice-new-profile]');
        const update = () => {
            if (fields) fields.hidden = method?.value !== 'sbp';
            if (fresh) fresh.hidden = Boolean(profiles?.value);
        };
        method?.addEventListener('change', update);
        profiles?.addEventListener('change', update);
        update();
    });
}
