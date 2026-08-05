const root = document.querySelector('[data-team-management]');
const invitation = root?.querySelector('[data-team-invitation]');
const pending = root?.querySelector('[data-team-pending-invitations]');

if (root && invitation && pending) {
    const form = invitation.querySelector('[data-team-invitation-form]');
    const idInput = invitation.querySelector('[data-team-user-id]');
    const results = invitation.querySelector('[data-team-user-results]');
    const feedback = invitation.querySelector('[data-team-form-feedback]');
    const list = pending.querySelector('[data-pending-invitations-list]');
    const empty = pending.querySelector('[data-pending-invitations-empty]');
    const count = pending.querySelector('[data-pending-invitations-count]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const showFeedback = (text, error = false) => {
        if (!feedback) return;
        feedback.className = `team-form-feedback alert ${error ? 'alert-danger' : 'alert-success'}`;
        feedback.textContent = text;
        feedback.hidden = false;
    };

    const refreshCount = () => {
        const total = list?.querySelectorAll('[data-pending-invitation-id]').length || 0;
        if (count) count.textContent = String(total);
        if (empty) empty.hidden = total > 0;
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();

        const data = new FormData(form);
        if (!idInput?.value) {
            showFeedback('Выберите пользователя из подсказки.', true);
            return;
        }

        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = true;

        try {
            const response = await fetch(invitation.dataset.storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    user_id: Number(idInput.value),
                    member_type: data.get('member_type'),
                    permissions: data.getAll('permissions[]'),
                }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось отправить приглашение.');
            }

            const invitationPayload = payload.invitation;
            if (invitationPayload?.html && list) {
                const template = document.createElement('template');
                template.innerHTML = invitationPayload.html.trim();
                const card = template.content.firstElementChild;
                const existing = list.querySelector(`[data-pending-invitation-id="${invitationPayload.id}"]`);
                if (card) {
                    existing ? existing.replaceWith(card) : list.prepend(card);
                }
            }

            form.reset();
            if (idInput) idInput.value = '';
            if (results) results.hidden = true;
            refreshCount();
            showFeedback(payload.message || 'Приглашение отправлено.');
        } catch (error) {
            showFeedback(error.message, true);
        } finally {
            if (submit) submit.disabled = false;
        }
    }, true);
}
