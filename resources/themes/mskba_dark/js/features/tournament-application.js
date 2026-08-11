document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tournament-application-role-form]').forEach((form) => {
        const roles = [...form.querySelectorAll('[data-tournament-application-role]')];
        if (roles.length === 0) {
            return;
        }

        const validate = () => {
            const message = roles.some((role) => role.checked) ? '' : 'Выберите хотя бы одну роль.';
            roles[0].setCustomValidity(message);
        };

        roles.forEach((role) => role.addEventListener('change', validate));
        form.addEventListener('submit', validate);
        validate();
    });

    document.addEventListener('notification:created', (event) => {
        const context = event.detail?.context;
        if (!['accepted', 'declined'].includes(context?.tournament_admission_status)) {
            return;
        }

        document.querySelectorAll(`[data-tournament-application-cta="${context.tournament_id}"]`).forEach((element) => element.remove());
        document.querySelector('[data-modal="tournament-application-role"]')?.remove();
    });
});
