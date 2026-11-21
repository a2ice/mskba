// The server remains authoritative; this timer only keeps the UI in sync.
// Expiry timestamps are Unix seconds rendered by Laravel.
import { createContactCodeAutoSubmit } from './contact-code-auto-submit.js';
import { refreshAccountContactsList } from './account-contacts-list.js';

const autoSubmit = createContactCodeAutoSubmit();
const formSelector = '[data-contact-code-expires-at]';

document.addEventListener('input', event => {
    const input = event.target;
    if (!input.matches?.('input[name="code"]')) return;
    const form = input.closest(formSelector);
    if (!form || input.disabled || Date.now() >= Number(form.dataset.contactCodeExpiresAt) * 1000) return;
    const id = form.elements._verification_contact_id?.value;
    if (autoSubmit.canSubmit(id, input.value)) form.requestSubmit();
});
document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches?.(formSelector)) return;
    const id = form.elements._verification_contact_id?.value;
    if (/^\d{6}$/.test(form.elements.code?.value || '')) autoSubmit.markAttempt(id);
});

let timer = null;
let refreshing = false;

function update() {
    const forms = [...document.querySelectorAll('[data-contact-code-expires-at]')];
    if (!forms.length) {
        clearInterval(timer);
        timer = null;
        return;
    }

    let expired = false;
    for (const form of forms) {
        const deadline = Number(form.dataset.contactCodeExpiresAt) * 1000;
        const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (!seconds) {
            form.querySelectorAll('input, button').forEach(control => { control.disabled = true; });
            expired = true;
        }
    }

    if (expired && !refreshing) {
        refreshing = true;
        refreshAccountContactsList()
            .catch(() => window.location.reload())
            .finally(() => { refreshing = false; });
    }
}

function start() {
    if (timer !== null) clearInterval(timer);
    timer = null;
    update();
    if (document.querySelector('[data-contact-code-expires-at]')) {
        timer = setInterval(update, 1000);
    }
}

start();
document.addEventListener('mskba:contacts-list-updated', start);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) update();
});
