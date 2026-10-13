const modalRoot = document.querySelector('[data-direct-message-dialog]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function jsonRequest(url, method = 'GET', payload = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest',
        },
        ...(payload ? { body: JSON.stringify(payload) } : {}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = Object.values(data.errors || {}).flat().find(Boolean);
        throw new Error(first || data.message || 'Не удалось выполнить запрос.');
    }
    return data;
}

if (modalRoot) {
    const dialog = modalRoot.querySelector('dialog');
    const check = dialog.querySelector('[data-direct-check]');
    const name = dialog.querySelector('[data-direct-recipient-name]');
    const form = dialog.querySelector('[data-direct-form]');
    const textarea = form.querySelector('textarea');
    const send = form.querySelector('button[type="submit"]');
    const success = dialog.querySelector('[data-direct-success]');
    const error = dialog.querySelector('[data-direct-error]');
    let recipient = null;
    let serial = 0;
    let opener = null;

    const urlFor = (key, id) => modalRoot.dataset[key].replace('__USER__', encodeURIComponent(id));
    const showError = text => { error.textContent = text; error.hidden = !text; dialog.dataset.surfaceTone = text ? 'error' : 'neutral'; };
    document.querySelectorAll('[data-direct-message-open]').forEach(button => {
        button.addEventListener('click', async () => {
            opener = button;
            recipient = button.dataset.recipientId;
            const current = ++serial;
            name.textContent = button.dataset.recipientName || 'Участник';
            name.hidden = false;
            dialog.dataset.surfaceTone = 'neutral';
            check.textContent = 'Проверяем возможность отправки…';
            check.hidden = false;
            form.hidden = true;
            success.hidden = true;
            showError('');
            textarea.value = '';
            dialog.showModal();
            dialog.querySelector('[tabindex="-1"]')?.focus({ preventScroll: true });
            try {
                const result = await jsonRequest(urlFor('accessUrl', recipient));
                if (current !== serial || !dialog.open) return;
                if (!result.can_message) {
                    check.textContent = result.message || 'Этот пользователь не принимает личные сообщения.';
                    return;
                }
                check.hidden = true;
                form.hidden = false;
                textarea.focus({ preventScroll: true });
            } catch (exception) {
                if (current === serial) {
                    check.hidden = true;
                    showError(exception.message);
                }
            }
        });
    });

    dialog.querySelector('[data-direct-dialog-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { ++serial; opener?.focus(); });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!recipient || send.disabled || !textarea.value.trim()) return;
        send.disabled = true;
        showError('');
        try {
            await jsonRequest(urlFor('sendUrl', recipient), 'POST', { body: textarea.value.trim() });
            form.hidden = true;
            success.hidden = false;
            success.textContent = 'Сообщение отправлено.';
            name.hidden = true;
            dialog.dataset.surfaceTone = 'success';
        } catch (exception) {
            showError(exception.message);
        } finally {
            send.disabled = false;
        }
    });
}

const directComposer = document.querySelector('[data-direct-thread-compose]');
if (directComposer) {
    const button = directComposer.querySelector('button[type="submit"]');
    const body = directComposer.querySelector('textarea');
    const error = directComposer.querySelector('[data-direct-thread-error]');
    directComposer.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !body.value.trim()) return;
        button.disabled = true;
        error.hidden = true;
        const text = body.value.trim();
        try {
            await jsonRequest(directComposer.dataset.sendUrl, 'POST', { body: text });
            const history = document.querySelector('.app-messages__history');
            const bubble = document.createElement('article');
            bubble.className = 'app-messages__bubble app-messages__bubble--outgoing';
            const head = document.createElement('div');
            head.className = 'app-messages__bubble-head';
            const author = document.createElement('strong');
            author.textContent = 'Вы';
            head.append(author);
            const message = document.createElement('p');
            message.className = 'app-messages__bubble-body';
            message.textContent = text;
            const time = document.createElement('time');
            time.className = 'app-messages__timestamp';
            time.dateTime = new Date().toISOString();
            time.textContent = new Date().toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
            bubble.append(head, message, time);
            history?.append(bubble);
            body.value = '';
            bubble.scrollIntoView({ block: 'end', behavior: 'smooth' });
        } catch (exception) {
            error.textContent = exception.message;
            error.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
}
