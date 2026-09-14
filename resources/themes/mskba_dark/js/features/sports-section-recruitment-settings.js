document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sports-section-recruitment-settings]').forEach((form) => {
        const acceptsRequests = form.querySelector('[data-section-accepts-requests]');
        const recruiting = form.querySelector('[data-section-recruiting]');
        const dependent = form.querySelector('[data-section-recruiting-dependent]');

        if (acceptsRequests && recruiting && dependent) {
            const syncRecruitment = () => {
                const enabled = acceptsRequests.checked;

                if (!enabled) {
                    recruiting.checked = false;
                }

                recruiting.disabled = !enabled;
                dependent.classList.toggle('is-disabled', !enabled);
                dependent.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            };

            acceptsRequests.addEventListener('change', syncRecruitment);
            syncRecruitment();
        }

        const modes = Array.from(form.querySelectorAll('[data-section-audience-mode]'));
        const exact = form.querySelector('[data-section-audience-exact]');
        const range = form.querySelector('[data-section-audience-range]');

        if (modes.length && exact && range) {
            const setGroupState = (group, enabled) => {
                group.hidden = !enabled;
                group.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.disabled = !enabled;
                });
            };

            const syncAudience = () => {
                const mode = modes.find((item) => item.checked)?.value || 'none';
                setGroupState(exact, mode === 'exact');
                setGroupState(range, mode === 'range');
            };

            modes.forEach((item) => item.addEventListener('change', syncAudience));
            syncAudience();
        }
    });
});
