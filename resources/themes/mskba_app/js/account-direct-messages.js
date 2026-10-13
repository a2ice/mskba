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
    const charCount = form.querySelector('[data-direct-char-count]');
    const updateCharCount = () => { if (charCount) charCount.textContent = `${textarea.value.length} / 200`; };
    textarea.addEventListener('input', updateCharCount);
    updateCharCount();
    const success = dialog.querySelector('[data-direct-success]');
    const error = dialog.querySelector('[data-direct-error]');
    let recipient = null;
    let serial = 0;
    let opener = null;
    const testTrigger = document.querySelector('[data-direct-test-open]');
    const testSearch = dialog.querySelector('[data-direct-test-search]');
    const testQuery = testSearch?.querySelector('[data-direct-test-query]');
    const testResults = testSearch?.querySelector('[data-direct-test-results]');
    const testStatus = testSearch?.querySelector('[data-direct-test-status]');
    const testBack = dialog.querySelector('[data-direct-test-back]');
    let searchSerial = 0;
    let searchDebounce = null;
    let isTestEntry = false;

    const urlFor = (key, id) => modalRoot.dataset[key].replace('__USER__', encodeURIComponent(id));
    const showError = text => { error.textContent = text; error.hidden = !text; dialog.dataset.surfaceTone = text ? 'error' : 'neutral'; };

    async function openRecipient(id, displayName, origin = null) {
        if (origin) opener = origin;
        recipient = id;
        const current = ++serial;
        if (testSearch) testSearch.hidden = true;
        if (testBack) testBack.hidden = !isTestEntry;
        name.textContent = displayName || 'Участник';
        name.hidden = false;
        dialog.dataset.surfaceTone = 'neutral';
        check.textContent = 'Проверяем возможность отправки…';
        check.hidden = false;
        form.hidden = true;
        success.hidden = true;
        showError('');
        textarea.value = '';
        updateCharCount();
        if (!dialog.open) dialog.showModal();
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
    }

    document.querySelectorAll('[data-direct-message-open]').forEach(button => {
        button.addEventListener('click', () => {
            isTestEntry = false;
            void openRecipient(button.dataset.recipientId, button.dataset.recipientName, button);
        });
    });

    if (testTrigger && testSearch && modalRoot.dataset.testSearchUrl) {
        const resetSearch = () => {
            ++serial;
            ++searchSerial;
            clearTimeout(searchDebounce);
            recipient = null;
            form.hidden = true;
            check.hidden = true;
            name.hidden = true;
            success.hidden = true;
            testBack.hidden = true;
            testSearch.hidden = false;
            showError('');
            testStatus.textContent = 'Начните вводить имя пользователя.';
            testResults.replaceChildren();
            dialog.dataset.surfaceTone = 'neutral';
            testQuery.focus({ preventScroll: true });
        };
        testTrigger.addEventListener('click', () => {
            opener = testTrigger;
            isTestEntry = true;
            if (!dialog.open) dialog.showModal();
            testQuery.value = '';
            resetSearch();
        });
        testBack.addEventListener('click', resetSearch);
        testQuery.addEventListener('input', () => {
            const q = testQuery.value.trim();
            const current = ++searchSerial;
            clearTimeout(searchDebounce);
            testResults.replaceChildren();
            if (q.length < 2) {
                testStatus.textContent = 'Введите минимум 2 символа.';
                return;
            }
            testStatus.textContent = 'Ищем участников…';
            searchDebounce = setTimeout(async () => {
                try {
                    const url = new URL(modalRoot.dataset.testSearchUrl, window.location.origin);
                    url.searchParams.set('q', q);
                    const data = await jsonRequest(url.toString());
                    if (current !== searchSerial || !dialog.open || testSearch.hidden) return;
                    const users = Array.isArray(data.users) ? data.users : [];
                    testStatus.textContent = users.length ? 'Выберите пользователя:' : 'Доступных пользователей не найдено.';
                    users.forEach(user => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'app-messages__direct-candidate';
                        button.textContent = user.name;
                        button.addEventListener('click', () => void openRecipient(user.id, user.name));
                        testResults.append(button);
                    });
                } catch (exception) {
                    if (current === searchSerial && dialog.open) testStatus.textContent = exception.message;
                }
            }, 240);
        });
    }

    dialog.querySelector('[data-direct-dialog-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        ++serial;
        ++searchSerial;
        clearTimeout(searchDebounce);
        opener?.focus();
    });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!recipient || send.disabled || !textarea.value.trim()) return;
        send.disabled = true;
        showError('');
        try {
            const result = await jsonRequest(urlFor('sendUrl', recipient), 'POST', { body: textarea.value.trim() });
            if (isTestEntry && result.conversation_url) {
                window.location.assign(result.conversation_url);
                return;
            }
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
