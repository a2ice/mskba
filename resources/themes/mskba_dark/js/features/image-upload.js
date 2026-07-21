export function setImageUploadLoading(target, loading) {
    const surface = resolveSurface(target);

    if (!surface) {
        return;
    }

    const overlay = surface.querySelector('[data-image-upload-overlay]');
    surface.classList.toggle('is-image-upload-loading', loading);
    surface.toggleAttribute('aria-busy', loading);

    if (overlay) {
        overlay.hidden = !loading;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-image-upload]').forEach((form) => {
        const surface = resolveSurface(form);
        surface?.querySelector('[data-image-upload-overlay]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });

        form.addEventListener('submit', () => setImageUploadLoading(form, true));

        if (!form.hasAttribute('data-image-upload-auto-submit')) {
            return;
        }

        const input = form.querySelector('input[type="file"]');
        input?.addEventListener('change', () => {
            if (input.files?.length) {
                form.requestSubmit();
            }
        });
    });
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-image-upload]').forEach((form) => setImageUploadLoading(form, false));
});

function resolveSurface(target) {
    if (!(target instanceof Element)) {
        return null;
    }

    return target.querySelector('[data-image-upload-surface]')
        || target.closest('[data-image-upload-surface]');
}
