import { initProfileInlineSave } from './profile-inline-save.js';

const PROFILE_AVATAR_INPUT_MAX_BYTES = 10 * 1024 * 1024;
const PROFILE_AVATAR_POST_MAX_BYTES = 768 * 1024;

// The outer development Nginx can reject multi-megabyte multipart requests
// before Laravel sees them. The backend stores a 200px WebP avatar, so downsize
// large uploads client-side without changing the original 10MB input contract.
async function prepareProfileAvatar(file) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        throw new Error('Поддерживаются только JPEG, PNG и WebP.');
    }
    if (file.size > PROFILE_AVATAR_INPUT_MAX_BYTES) {
        throw new Error('Файл аватара должен быть не больше 10 МБ.');
    }
    if (file.size <= PROFILE_AVATAR_POST_MAX_BYTES) return file;

    const url = URL.createObjectURL(file);
    const image = new Image();
    try {
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Не удалось открыть изображение.'));
            image.src = url;
        });
    } finally {
        URL.revokeObjectURL(url);
    }

    if (!image.naturalWidth || !image.naturalHeight
        || image.naturalWidth > 6000 || image.naturalHeight > 6000) {
        throw new Error('Размер изображения не должен превышать 6000×6000 пикселей.');
    }

    for (const [edge, quality] of [[800, 0.84], [600, 0.75], [400, 0.65]]) {
        const scale = Math.min(1, edge / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Не удалось обработать фотографию в браузере.');
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', quality));
        canvas.width = 0;
        canvas.height = 0;
        if (blob?.type === 'image/webp' && blob.size <= PROFILE_AVATAR_POST_MAX_BYTES) {
            const name = file.name.replace(/\.[^.]+$/, '') || 'avatar';
            return new File([blob], name + '.webp', { type: 'image/webp' });
        }
    }

    throw new Error('Не удалось уменьшить фотографию. Попробуй другое изображение.');
}


// Task 026: use existing avatar forms, nickname JSON endpoint and Modal 1.1 shell.
const profileRoot = document.querySelector('[data-account-profile]');

if (profileRoot) {
    const uploadForm = profileRoot.querySelector('[data-profile-avatar-upload]');
    uploadForm?.querySelector('input[type=file]')?.addEventListener('change', async event => {
        const input = event.target;
        const file = input.files?.[0];
        if (!file) return;

        const state = profileRoot.querySelector('[data-profile-avatar-upload-status]');
        if (state) {
            state.hidden = false;
            state.textContent = 'Подготавливаем фотографию…';
        }
        uploadForm.setAttribute('aria-busy', 'true');

        try {
            const upload = await prepareProfileAvatar(file);
            if (upload !== file) {
                // Keep the existing native POST, CSRF and server-side validation.
                const transfer = new DataTransfer();
                transfer.items.add(upload);
                input.files = transfer.files;
            }
            if (state) state.textContent = 'Загружаем…';
            uploadForm.submit();
        } catch (error) {
            input.value = '';
            uploadForm.removeAttribute('aria-busy');
            if (state) state.textContent = error.message || 'Не удалось подготовить изображение.';
        }
    });

    const personalForm = profileRoot.querySelector('[data-profile-personal-form]');
    if (personalForm) {
        const editor = initProfileInlineSave(personalForm);
        personalForm.addEventListener('submit', event => {
            event.preventDefault();
            if (editor.busy()) return;
            void editor.save(editor.states.filter(editor.dirty), true);
        });
    }

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
