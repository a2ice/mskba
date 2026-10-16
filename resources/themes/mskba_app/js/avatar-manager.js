// Avatar forms retain native POST fallback when JavaScript is unavailable.
const MAX_AVATAR_BYTES = 10 * 1024 * 1024;
const SVG_NS = 'http://www.w3.org/2000/svg';

function avatarPlaceholder(className, size = null) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', className);
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    if (size) {
        svg.setAttribute('width', String(size));
        svg.setAttribute('height', String(size));
    }
    const use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', '#user');
    svg.append(use);
    return svg;
}

function imageOrPlaceholder(url, imageClass, placeholderClass, size) {
    if (!url) return avatarPlaceholder(placeholderClass, imageClass ? null : 48);
    const img = document.createElement('img');
    if (imageClass) img.className = imageClass;
    img.src = url;
    img.alt = imageClass ? '' : 'Твой текущий аватар';
    if (size) {
        img.width = size;
        img.height = size;
    }
    return img;
}

function hiddenField(name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    return input;
}

function actionForm(url, method, csrf, label, className, ariaLabel = '') {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.setAttribute('data-profile-avatar-action', '');
    form.append(hiddenField('_token', csrf), hiddenField('_method', method));
    const button = document.createElement('button');
    button.type = 'submit';
    button.className = className;
    button.textContent = label;
    if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
    form.append(button);
    return form;
}

export function initAvatarManager(profileRoot) {
    const uploadForm = profileRoot.querySelector('[data-profile-avatar-upload]');
    if (!uploadForm) return;
    const input = uploadForm.querySelector('input[type=file]');
    const csrf = uploadForm.querySelector('input[name="_token"]')?.value || '';
    const preview = profileRoot.querySelector('[data-profile-avatar-preview]');
    const library = profileRoot.querySelector('[data-profile-avatar-library]');
    const state = profileRoot.querySelector('[data-profile-avatar-upload-status]');
    let busy = false;

    function feedback(message, error = false) {
        if (!state) return;
        state.hidden = !message;
        state.dataset.error = String(error);
        state.textContent = message;
    }

    function setBusy(value) {
        busy = value;
        uploadForm.setAttribute('aria-busy', String(value));
        input.disabled = value;
        library?.querySelectorAll('button').forEach(button => { button.disabled = value; });
    }

    function render(data) {
        if (!data || !Array.isArray(data.avatars)) {
            throw new Error('Сервер вернул некорректные данные аватаров.');
        }
        const url = data.avatar_url || null;
        preview?.replaceChildren(imageOrPlaceholder(url, '', 'app-profile__avatar-placeholder', 112));
        // Synchronize the signed-in user's avatar in the site header.
        document.querySelectorAll('[data-app-header] .app-user-avatar__visual')
            .forEach(visual => visual.replaceChildren(
                imageOrPlaceholder(url, 'app-user-avatar__image', 'app-user-avatar__placeholder', null),
            ));
        if (!library) return;

        const items = data.avatars.map((avatar, i) => {
            const card = document.createElement('div');
            card.className = 'app-profile__avatar-item';
            const img = document.createElement('img');
            img.src = avatar.url;
            img.alt = 'Аватар ' + (i + 1);
            img.width = 76;
            img.height = 76;
            card.append(img);

            if (avatar.is_featured) {
                const marker = document.createElement('span');
                marker.className = 'app-profile__avatar-current';
                marker.textContent = 'Основной';
                card.append(marker);
            } else {
                card.append(actionForm(
                    avatar.activate_url, 'PATCH', csrf, 'Сделать основным', 'app-profile__text-action',
                ));
            }
            card.append(actionForm(
                avatar.delete_url, 'DELETE', csrf, 'Удалить',
                'app-profile__text-action app-profile__text-action--muted',
                'Удалить аватар ' + (i + 1),
            ));
            return card;
        });
        library.replaceChildren(...items);
        library.hidden = items.length === 0;
    }

    async function request(form) {
        if (busy) return;
        // Build FormData before disabling the file input: disabled fields
        // are excluded. The original image is sent without Canvas conversion.
        const body = new FormData(form);
        setBusy(true);
        feedback('Сохраняем аватар…');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.errors?.avatar?.[0] || data.message
                    || (response.status === 413 ? 'Файл слишком большой для сервера.' : 'Не удалось сохранить аватар.'));
            }
            render(data);
            feedback(data.message || 'Аватар обновлён.');
        } catch (error) {
            feedback(error.message || 'Не удалось сохранить аватар.', true);
        } finally {
            input.value = '';
            setBusy(false);
        }
    }

    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file || busy) return;
        if (file.size > MAX_AVATAR_BYTES) {
            input.value = '';
            feedback('Файл аватара должен быть не больше 10 МБ.', true);
            return;
        }
        void request(uploadForm);
    });

    // Delegation covers both SSR forms and those added after AJAX requests.
    profileRoot.addEventListener('submit', event => {
        const form = event.target.closest?.('[data-profile-avatar-action]');
        if (form && profileRoot.contains(form)) {
            event.preventDefault();
            void request(form);
        }
    });
}
