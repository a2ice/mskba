document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sports-section-recruitment-settings]').forEach((form) => {
        const acceptsRequests = form.querySelector('[data-section-accepts-requests]');
        const recruiting = form.querySelector('[data-section-recruiting]');
        const dependent = form.querySelector('[data-section-recruiting-dependent]');

        if (!acceptsRequests || !recruiting || !dependent) {
            return;
        }

        const sync = () => {
            const enabled = acceptsRequests.checked;

            if (!enabled) {
                recruiting.checked = false;
            }

            recruiting.disabled = !enabled;
            dependent.classList.toggle('is-disabled', !enabled);
            dependent.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        };

        acceptsRequests.addEventListener('change', sync);
        sync();
    });
});
