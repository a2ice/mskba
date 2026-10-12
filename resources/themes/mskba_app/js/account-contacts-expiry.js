// The server remains authoritative; this timer only keeps the UI in sync.
// Expiry timestamps are Unix seconds rendered by Laravel.
import { refreshAccountContactsList } from './account-contacts-list.js';

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
