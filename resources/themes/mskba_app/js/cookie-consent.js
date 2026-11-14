// The form remains functional without JS; AJAX only avoids a full error-page navigation.
function initCookieConsent() {
    const banner = document.querySelector('[data-cookie-consent-banner]');
    const form = banner?.querySelector('[data-cookie-consent-form]');
    if (!banner || !form) return;

    document.querySelectorAll('[data-cookie-consent-open]').forEach((trigger) => {
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

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ analytics: choice }),
            });
            if (!response.ok) throw new Error('Consent request failed');
            // Reload guarantees that Metrika starts only after the server has accepted
            // consent, and stops loading on every subsequent page after revocation.
            window.location.reload();
        } catch {
            if (error) error.hidden = false;
            buttons.forEach((button) => { button.disabled = false; });
        }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCookieConsent, { once: true });
} else {
    initCookieConsent();
}
