document.addEventListener('DOMContentLoaded', () => {
    const toWizardUrl = (value) => {
        if (!value) return value;

        let url;
        try {
            url = new URL(value, window.location.origin);
        } catch (_) {
            return value;
        }

        if (url.origin !== window.location.origin || url.pathname !== '/events/create') {
            return value;
        }

        url.pathname = '/events/create/wizard';

        return value.startsWith('http://') || value.startsWith('https://')
            ? url.toString()
            : `${url.pathname}${url.search}${url.hash}`;
    };

    document.querySelectorAll('a[href]').forEach((link) => {
        const rewritten = toWizardUrl(link.getAttribute('href'));
        if (rewritten) link.setAttribute('href', rewritten);
    });

    document.querySelectorAll('[data-auth-redirect-url]').forEach((element) => {
        const rewritten = toWizardUrl(element.getAttribute('data-auth-redirect-url'));
        if (rewritten) element.setAttribute('data-auth-redirect-url', rewritten);
    });
});
