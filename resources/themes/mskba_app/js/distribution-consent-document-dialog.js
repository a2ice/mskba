// Task 019/023: legal modal shell from the Wizard, with one canonical Blade source.
const consentUrl = '/legal-fragments/personal-data-distribution-consent';
const linksSelector = '[data-distribution-legal-open]';
let panel = null;
let body = null;
let opener = null;
let request = null;
let busy = false;
let previousOverflow = '';
let fallbackUrl = '/personal-data-distribution-consent';
let loadedContent = null;

function ensureDialog() {
    if (panel) return;

    panel = document.createElement('dialog');
    panel.className = 'mskba-modal mskba-legal-dialog mskba-distribution-legal-dialog';
    panel.setAttribute('aria-labelledby', 'mskba-distribution-legal-dialog-title');
    panel.innerHTML = [
        '<header class="mskba-modal__header mskba-legal-dialog__header">',
        ' <h2 id="mskba-distribution-legal-dialog-title" tabindex="-1">Согласие на обработку персональных данных, разрешённых для распространения</h2>',
        ' <button type="button" class="icon-button" data-legal-close aria-label="Закрыть документ">',
        '  <svg aria-hidden="true"><use href="#close"/></svg></button>',
        '</header>',
        '<div class="mskba-modal__body mskba-scroll mskba-legal-dialog__body"',
        ' role="region" aria-label="Согласие на распространение персональных данных" tabindex="0"></div>',
        '<footer class="mskba-modal__footer mskba-legal-dialog__footer">',
        ' <button type="button" class="button secondary" data-legal-close>Вернуться к настройкам</button>',
        '</footer>',
    ].join('');
    document.body.append(panel);
    body = panel.querySelector('.mskba-legal-dialog__body');

    panel.querySelectorAll('[data-legal-close]').forEach(button => {
        button.addEventListener('click', closeDialog);
    });
    panel.addEventListener('cancel', event => {
        event.preventDefault();
        closeDialog();
    });
    panel.addEventListener('click', event => {
        if (event.target !== panel) return;
        const rect = panel.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right
            || event.clientY < rect.top || event.clientY > rect.bottom) {
            closeDialog();
        }
    });
}

function closeDialog() {
    if (!panel?.open) return;
    request?.abort();
    request = null;
    busy = false;
    panel.close();
    document.body.style.overflow = previousOverflow;
    const trigger = opener;
    opener = null;
    // Opening focuses the neutral heading; closing restores the original link.
    if (trigger?.isConnected) trigger.focus({ preventScroll: true });
}

function showFailure() {
    body.replaceChildren();
    const container = document.createElement('div');
    container.className = 'mskba-legal-dialog__notice';
    container.setAttribute('role', 'alert');
    const text = document.createElement('p');
    text.textContent = 'Не удалось загрузить документ. Попробуй снова или открой полную страницу.';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'button secondary';
    retry.textContent = 'Повторить';
    retry.addEventListener('click', loadDocument);
    const link = document.createElement('a');
    link.href = fallbackUrl;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Открыть документ отдельно';
    container.append(text, retry, link);
    body.append(container);
}

async function loadDocument() {
    if (!panel?.open || busy) return;
    request?.abort();
    body.innerHTML = '<p class="mskba-legal-dialog__notice" role="status">Загружаем документ…</p>';
    if (loadedContent !== null) {
        body.innerHTML = loadedContent;
        return;
    }

    const controller = new AbortController();
    request = controller;
    busy = true;
    try {
        // Only this fixed, public, same-origin legal fragment is requested.
        const response = await fetch(consentUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) {
            throw new Error('Legal fragment unavailable');
        }
        const content = await response.text();
        if (controller.signal.aborted || !panel.open) return;
        loadedContent = content;
        body.innerHTML = loadedContent; // Trusted server-rendered fixed Blade fragment.
        body.scrollTo({ top: 0 });
    } catch (error) {
        if (error.name !== 'AbortError' && panel.open) showFailure();
    } finally {
        if (request === controller) {
            busy = false;
            request = null;
        }
    }
}

function openDialog(trigger) {
    ensureDialog();
    if (panel.open) return;
    opener = trigger;
    fallbackUrl = trigger.href;
    previousOverflow = document.body.style.overflow;
    panel.showModal(); // Browser top layer, above the existing onboarding dialog.
    document.body.style.overflow = 'hidden';
    panel.querySelector('#mskba-distribution-legal-dialog-title')?.focus({ preventScroll: true });
    body.scrollTo({ top: 0 });
    loadDocument();
}

document.addEventListener('click', event => {
    const trigger = event.target.closest?.(linksSelector);
    if (!trigger) return;
    event.preventDefault();
    openDialog(trigger);
});
