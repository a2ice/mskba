// Task 026: use existing avatar forms, nickname JSON endpoint and Modal 1.1 shell.
const profileRoot = document.querySelector('[data-account-profile]');

if (profileRoot) {
    const uploadForm = profileRoot.querySelector('[data-profile-avatar-upload]');
    uploadForm?.querySelector('input[type=file]')?.addEventListener('change', event => {
        if (!event.target.files?.length) return;
        const state = profileRoot.querySelector('[data-profile-avatar-upload-status]');
        if (state) state.hidden = false;
        uploadForm.setAttribute('aria-busy', 'true');
        // Native multipart/form POST: server validates and processes the image.
        uploadForm.submit();
    });

    const nicknameForm = profileRoot.querySelector('[data-profile-nickname-form]');
    if (nicknameForm) {
        const input = nicknameForm.querySelector('[name=nickname]');
        const status = nicknameForm.querySelector('[data-profile-nickname-feedback]');
        const submit = nicknameForm.querySelector('button[type=submit]');
        nicknameForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (!nicknameForm.reportValidity()) return;
            submit.disabled = true;
            status.textContent = 'Сохраняем…';
            status.dataset.error = 'false';
            try {
                const response = await fetch(nicknameForm.action, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': nicknameForm.querySelector('input[name=_token]')?.value || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({nickname: input.value}),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const reason = data.errors?.nickname?.[0] || data.message || 'Не удалось сохранить никнейм.';
                    throw new Error(reason);
                }
                input.value = data.nickname || '';
                const publicUrl = profileRoot.querySelector('[data-profile-public-url]');
                if (publicUrl && typeof data.public_url === 'string') {
                    publicUrl.href = data.public_url;
                    publicUrl.textContent = data.public_url;
                }
                status.textContent = 'Никнейм сохранён. Ссылка на профиль обновлена.';
            } catch (error) {
                status.dataset.error = 'true';
                status.textContent = error.message || 'Не удалось сохранить никнейм.';
            } finally {
                submit.disabled = false;
            }
        });
    }
}

function bindProfileDialog(triggerSelector, panelSelector, closeSelector) {
    const dialog = document.querySelector(panelSelector);
    const trigger = document.querySelector(triggerSelector);
    if (!dialog || !trigger) return;

    trigger.addEventListener('click', () => {
        dialog.showModal();
        dialog.querySelector('h2[tabindex="-1"]')?.focus({ preventScroll: true });
    });
    dialog.querySelectorAll(closeSelector).forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        if (trigger.isConnected) trigger.focus({ preventScroll: true });
    });
}

bindProfileDialog(
    '[data-profile-change-request-open]',
    '[data-profile-change-request-dialog]',
    '[data-profile-change-request-close]',
);
