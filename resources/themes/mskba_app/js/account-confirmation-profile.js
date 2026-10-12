import { initProfileInlineSave } from './profile-inline-save.js';

const form = document.querySelector('[data-confirmation-profile]');
if (form) {
    const submit = document.querySelector('[data-confirmation-submit]');
    const requirement = document.querySelector('[data-confirmation-requirement]');
    const status = form.querySelector('[data-confirmation-profile-state]');
    const contactVerified = form.dataset.hasVerifiedContact === 'true';

    const editor = initProfileInlineSave(form, {
        onChange: ({ states, dirty, busy }) => {
            const missing = states.filter(state => state.required && !state.saved.trim());
            const pending = states.some(dirty);
            if (submit) submit.disabled = busy || pending || !!missing.length || !contactVerified;
            if (status) {
                status.textContent = missing.length === 0 ? 'Заполнено' : 'Требует заполнения';
                status.classList.toggle('app-confirmation__state--done', missing.length === 0);
            }
            if (!requirement) return;
            const needed = [...missing];
            if (!contactVerified) needed.push({ name: 'contact' });
            if (!needed.length) {
                requirement.textContent = 'Все обязательные данные заполнены. Теперь можно подтвердить аккаунт.';
                return;
            }
            const first = needed[0];
            const anchor = document.createElement('a');
            anchor.href = '#' + (first.name === 'contact'
                ? 'confirmation-main-contact' : first.input.id);
            anchor.dataset.confirmationMissingLink = '';
            anchor.textContent = first.name === 'contact'
                ? 'подтвердить основной контакт'
                : 'заполнить ' + first.node.querySelector('label').firstChild.textContent.trim().toLowerCase();
            requirement.replaceChildren('Для подтверждения аккаунта необходимо ', anchor,
                needed.length > 1 ? ' и ещё ' + (needed.length - 1) : '', '.');
        },
    });
    // Account confirmation is a separate action, never an implicit save-all.
    form.addEventListener('submit', event => {
        if (editor.busy() || !contactVerified ||
            editor.states.some(state => editor.dirty(state) || (state.required && !state.saved.trim()))) {
            event.preventDefault();
            editor.refresh();
        }
    });
    requirement?.addEventListener('click', event => {
        const link = event.target.closest('[data-confirmation-missing-link]');
        if (!link) return;
        const target = document.getElementById(link.hash.substring(1));
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (target.matches('input,select,textarea')) target.focus({ preventScroll: true });
    });
}
