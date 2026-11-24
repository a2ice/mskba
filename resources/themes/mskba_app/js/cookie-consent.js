// Consent is saved by the server; the page no longer needs to reload after AJAX.
// The non-JS POST/redirect flow stays available as progressive enhancement.
export function initCookieConsent(doc = document, host = window, fetchRequest = fetch) {
    const banner = doc.querySelector('[data-cookie-consent-banner]');
    const form = banner?.querySelector('[data-cookie-consent-form]');
    if (!banner || !form) return;

    doc.querySelectorAll('[data-cookie-consent-open]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            banner.hidden = false;
            banner.querySelector('button[name="analytics"]')?.focus();
        });
    });

    form.addEventListener('submit', async (event) => {
        const choice = event.submitter?.value;
        if (!['accepted', 'rejected'].includes(choice)) return;
        event.preventDefault();

        const buttons = Array.from(form.querySelectorAll('button'));
        const error = banner.querySelector('[data-cookie-consent-error]');
        if (error) error.hidden = true;
        buttons.forEach((button) => { button.disabled = true; });

        const notify = (message, failed = false) => host.dispatchEvent(
            new CustomEvent('mskba:action-toast', { detail: { message, failed } })
        );

        try {
            const response = await fetchRequest(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ analytics: choice }),
            });
            if (!response.ok) throw new Error('Consent request failed');
            const saved = await response.json();
            if (saved?.analytics !== choice) throw new Error('Consent response mismatch');

            banner.dataset.cookieConsentState = choice;
            banner.hidden = true;
            notify(choice === 'accepted' ? 'Все cookie приняты' : 'Выбраны только необходимые cookie');
        } catch {
            // Keep the banner visible and the decision unchanged on ANY failure.
            banner.hidden = false;
            if (error) error.hidden = false;
            notify('Не удалось сохранить настройки cookie. Попробуйте ещё раз.', true);
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initCookieConsent(), { once: true });
    } else {
        initCookieConsent();
    }
}
