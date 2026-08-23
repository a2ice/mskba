document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach((form) => {
        const titleInput = form.querySelector('[data-wizard-title]');
        if (!titleInput) return;

        const serverValue = titleInput.value.trim();
        const initialDefault = String(form.dataset.defaultTitle || '').trim();

        // On a fresh wizard page the server value is the initial placeholder
        // title and the main wizard is free to regenerate it from type/format/date.
        // After a validation redirect the submitted title differs from that
        // initial placeholder; mark it as user state before event-wizard.js runs
        // so initialization cannot overwrite it.
        if (serverValue && initialDefault && serverValue !== initialDefault) {
            titleInput.dataset.generatedTitle = '0';
        }
    });
});
