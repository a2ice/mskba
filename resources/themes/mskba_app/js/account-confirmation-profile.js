import { initProfileInlineSave } from './profile-inline-save.js';

const form = document.querySelector('[data-confirmation-profile]');
if (form) {
    // The confirmation action lives in the shared intro panel. The shared gate
    // opens a missing-data dialog even when fields/contact are incomplete.
    // This editor only manages the independently persisted personal fields.
    const status = form.querySelector('[data-confirmation-profile-state]');
    initProfileInlineSave(form, {
        onChange: ({ states }) => {
            const missing = states.filter(state => state.required && !state.saved.trim());
            if (!status) return;
            status.textContent = missing.length ? 'Требует заполнения' : 'Заполнено';
            status.classList.toggle('app-confirmation__state--done', !missing.length);
        },
    });
}
