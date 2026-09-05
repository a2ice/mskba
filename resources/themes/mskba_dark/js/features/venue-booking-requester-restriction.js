document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-venue-booking-page]');
    if (!page) return;

    const bookingId = page.dataset.bookingId;
    if (!bookingId) return;

    const endpoint = `/account/venue-bookings/${encodeURIComponent(bookingId)}/requester-restriction`;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    fetch(endpoint, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then((response) => {
            if (response.status === 401 || response.status === 403) return null;
            if (!response.ok) throw new Error('requester restriction state failed');
            return response.json();
        })
        .then((payload) => {
            if (!payload) return;
            renderRestrictionControls(page, payload, csrfToken);
        })
        .catch(() => {
            // Administrative helper must not affect the booking page itself.
        });
});

function renderRestrictionControls(page, payload, csrfToken) {
    const summary = page.querySelector('.venue-booking-summary');
    if (!summary || page.querySelector('[data-rental-requester-restriction]')) return;

    const card = document.createElement('div');
    card.className = 'card mb-4';
    card.dataset.rentalRequesterRestriction = '';

    const body = document.createElement('div');
    body.className = 'card-body';
    card.append(body);

    const title = document.createElement('h2');
    title.className = 'h4';
    title.textContent = 'Ограничение повторных заявок';
    body.append(title);

    const description = document.createElement('p');
    description.className = 'text-muted';
    description.textContent = 'Административное ограничение действует на новые заявки этого пользователя только по этой площадке и не меняет статус текущей брони.';
    body.append(description);

    if (payload.restricted) {
        const state = document.createElement('div');
        state.className = 'alert alert-warning';
        const strong = document.createElement('strong');
        strong.textContent = 'Повторные заявки заблокированы.';
        state.append(strong);
        if (payload.reason) {
            const reason = document.createElement('div');
            reason.textContent = payload.reason;
            state.append(reason);
        }
        body.append(state);

        if (payload.revoke_url) {
            const form = formFor(payload.revoke_url, csrfToken);
            const label = document.createElement('label');
            label.className = 'form-label';
            label.textContent = 'Комментарий при снятии ограничения';
            const input = document.createElement('input');
            input.className = 'form-control mb-2';
            input.name = 'reason';
            input.maxLength = 2000;
            label.append(input);
            form.append(label);
            form.append(button('Снять ограничение', 'btn btn--secondary btn--sm'));
            body.append(form);
        }
    } else if (payload.block_url) {
        const form = formFor(payload.block_url, csrfToken);
        const label = document.createElement('label');
        label.className = 'form-label';
        label.textContent = 'Причина блокировки повторных заявок';
        const textarea = document.createElement('textarea');
        textarea.className = 'form-control mb-2';
        textarea.name = 'reason';
        textarea.required = true;
        textarea.minLength = 5;
        textarea.maxLength = 2000;
        textarea.rows = 3;
        label.append(textarea);
        form.append(label);
        form.append(button('Запретить повторные заявки', 'btn btn--secondary btn--sm'));
        body.append(form);
    }

    summary.insertAdjacentElement('afterend', card);
}

function formFor(action, csrfToken) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = csrfToken;
    form.append(csrf);
    return form;
}

function button(label, className) {
    const node = document.createElement('button');
    node.type = 'submit';
    node.className = className;
    node.textContent = label;
    return node;
}
