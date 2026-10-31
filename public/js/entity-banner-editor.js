(() => {
    'use strict';
    if (window.__mskbaBannerEditorInitialized) return;
    window.__mskbaBannerEditorInitialized = true;

    const WIDTH = 1200;
    const HEIGHT = 400;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    function setup(root) {
        if (root.dataset.bannerReady) return;
        root.dataset.bannerReady = '1';

        const fileInput = root.querySelector('[data-banner-file]');
        const fileName = root.querySelector('[data-banner-file-name]');
        const canvas = root.querySelector('[data-banner-canvas]');
        const ctx = canvas.getContext('2d');
        const zoomInput = root.querySelector('[data-banner-zoom]');
        const zoomIn = root.querySelector('[data-banner-zoom-in]');
        const zoomOut = root.querySelector('[data-banner-zoom-out]');
        const reset = root.querySelector('[data-banner-reset]');
        const save = root.querySelector('[data-banner-save]');
        const remove = root.querySelector('[data-banner-delete]');
        const empty = root.querySelector('[data-banner-empty]');
        const status = root.querySelector('[data-banner-status]');
        const confirmation = root.querySelector('[data-banner-delete-confirm]');
        const deleteYes = root.querySelector('[data-banner-delete-yes]');
        const deleteCancel = root.querySelector('[data-banner-delete-cancel]');
        const help = 'JPG, PNG или WebP, до 10 МБ';
        let image = null;
        let file = null;
        let objectUrl = null;
        let busy = false;
        let zoom = 1;
        let x = 0;
        let y = 0;
        let dragging = null;

        function message(value, error = false) {
            status.textContent = value;
            status.classList.toggle('is-error', error);
        }

        function dimensions() {
            if (!image) return null;
            const fit = Math.max(WIDTH / image.naturalWidth, HEIGHT / image.naturalHeight);
            return {
                width: image.naturalWidth * fit * zoom,
                height: image.naturalHeight * fit * zoom,
                fit,
            };
        }

        function center() {
            const size = dimensions();
            if (!size) return;
            x = (WIDTH - size.width) / 2;
            y = (HEIGHT - size.height) / 2;
        }

        function constrain() {
            const size = dimensions();
            if (!size) return;
            x = clamp(x, WIDTH - size.width, 0);
            y = clamp(y, HEIGHT - size.height, 0);
        }

        function setBusy(next) {
            busy = next;
            save.disabled = next || !image;
            remove.disabled = next;
            reset.disabled = next || !image;
            zoomInput.disabled = next || !image;
            zoomIn.disabled = next || !image;
            zoomOut.disabled = next || !image;
            fileInput.disabled = next;
        }

        function render() {
            if (!ctx || !image) return;
            constrain();
            const size = dimensions();
            ctx.clearRect(0, 0, WIDTH, HEIGHT);
            ctx.drawImage(image, x, y, size.width, size.height);
            empty.hidden = true;
            setBusy(busy);
        }

        function restoreSavedCrop(crop) {
            if (!crop || !image || !['x', 'y', 'width', 'height'].every(key => Number.isFinite(Number(crop[key])))) {
                center();
                return;
            }
            const w = Number(crop.width);
            const h = Number(crop.height);
            if (w <= 0 || h <= 0) {
                center();
                return;
            }
            const base = dimensions();
            const needed = Math.max(
                WIDTH / (w * base.width),
                HEIGHT / (h * base.height),
            );
            zoom = Math.max(1, needed);
            zoomInput.max = String(Math.max(3, Math.ceil(zoom * 100) / 100));
            zoomInput.value = String(zoom);
            const size = dimensions();
            x = -Number(crop.x) * size.width;
            y = -Number(crop.y) * size.height;
        }

        function load(source, selectedFile = null, savedCrop = null) {
            const next = new Image();
            next.onload = () => {
                image = next;
                file = selectedFile;
                zoom = 1;
                zoomInput.max = '3';
                zoomInput.value = '1';
                restoreSavedCrop(savedCrop);
                render();
                message(selectedFile ? 'Настройте положение изображения и сохраните.' : '');
            };
            next.onerror = () => message('Не удалось открыть изображение.', true);
            next.src = source;
        }

        function revoke() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        fileInput.addEventListener('change', () => {
            const candidate = fileInput.files?.[0];
            if (!candidate) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(candidate.type)
                || candidate.size > 10 * 1024 * 1024) {
                fileInput.value = '';
                message('Выберите изображение JPG, PNG или WebP до 10 МБ.', true);
                return;
            }
            revoke();
            objectUrl = URL.createObjectURL(candidate);
            fileName.textContent = candidate.name;
            confirmation.hidden = true;
            load(objectUrl, candidate);
        });

        function applyZoom(next) {
            if (!image || busy) return;
            const before = dimensions();
            const cx = (WIDTH / 2 - x) / before.width;
            const cy = (HEIGHT / 2 - y) / before.height;
            zoom = clamp(next, 1, Number(zoomInput.max));
            zoomInput.value = String(zoom);
            const after = dimensions();
            x = WIDTH / 2 - cx * after.width;
            y = HEIGHT / 2 - cy * after.height;
            render();
        }

        zoomInput.addEventListener('input', () => applyZoom(Number(zoomInput.value)));
        zoomIn.addEventListener('click', () => applyZoom(zoom * 1.2));
        zoomOut.addEventListener('click', () => applyZoom(zoom / 1.2));

        reset.addEventListener('click', () => {
            if (!image || busy) return;
            zoom = 1;
            zoomInput.max = '3';
            zoomInput.value = '1';
            center();
            render();
        });

        canvas.addEventListener('pointerdown', event => {
            if (!image || busy || (event.pointerType === 'mouse' && event.button !== 0)) return;
            dragging = {id:event.pointerId, pointerX:event.clientX, pointerY:event.clientY, x, y};
            canvas.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        canvas.addEventListener('pointermove', event => {
            if (!dragging || dragging.id !== event.pointerId) return;
            const bounds = canvas.getBoundingClientRect();
            x = dragging.x + (event.clientX - dragging.pointerX) * WIDTH / bounds.width;
            y = dragging.y + (event.clientY - dragging.pointerY) * HEIGHT / bounds.height;
            render();
        });

        for (const eventName of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            canvas.addEventListener(eventName, () => { dragging = null; });
        }

        function crop() {
            const size = dimensions();
            if (!size) return null;
            return {
                x: Number(clamp(-x / size.width, 0, 1).toFixed(6)),
                y: Number(clamp(-y / size.height, 0, 1).toFixed(6)),
                width: Number(clamp(WIDTH / size.width, 0, 1).toFixed(6)),
                height: Number(clamp(HEIGHT / size.height, 0, 1).toFixed(6)),
            };
        }

        async function request(url, method, form = null) {
            const response = await fetch(url, {
                method,
                body: form,
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': root.querySelector('[data-banner-csrf]').value,
                    Accept: 'application/json',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const first = Object.values(payload.errors || {})[0];
                throw new Error(first?.[0] || payload.message || 'Не удалось сохранить изменения.');
            }
            return payload;
        }

        save.addEventListener('click', async () => {
            if (!image || busy) return;
            confirmation.hidden = true;
            const selection = crop();
            const form = new FormData();
            if (file) form.append('image', file);
            for (const [key, value] of Object.entries(selection)) {
                form.append('crop[' + key + ']', String(value));
            }

            setBusy(true);
            message('Сохраняем…');
            try {
                const data = await request(root.dataset.uploadUrl, 'POST', form);
                remove.hidden = false;
                file = null;
                fileInput.value = '';
                fileName.textContent = help;
                root.dataset.initialCrop = JSON.stringify(selection);
                root.dataset.originalUrl ||= root.dataset.uploadUrl.replace(/\/$/, '') + '/source';
                message(data.message || 'Баннер сохранён.');
                // Keep the same visible crop after saving; no jump back to the centre.
            } catch (error) {
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        remove.addEventListener('click', () => {
            if (busy) return;
            confirmation.hidden = false;
            deleteCancel.focus();
        });
        deleteCancel.addEventListener('click', () => {
            confirmation.hidden = true;
            remove.focus();
        });

        deleteYes.addEventListener('click', async () => {
            if (busy) return;
            setBusy(true);
            message('Удаляем…');
            try {
                const data = await request(root.dataset.deleteUrl, 'DELETE');
                remove.hidden = true;
                confirmation.hidden = true;
                fileInput.value = '';
                file = null;
                image = null;
                dragging = null;
                revoke();
                ctx?.clearRect(0, 0, WIDTH, HEIGHT);
                empty.hidden = false;
                fileName.textContent = help;
                root.dataset.originalUrl = '';
                root.dataset.initialCrop = '';
                message(data.message || 'Баннер удалён.');
                fileInput.focus();
            } catch (error) {
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        if (root.dataset.originalUrl) {
            fetch(root.dataset.originalUrl, {credentials:'same-origin'})
                .then(response => {
                    if (!response.ok) throw new Error('Не удалось загрузить изображение.');
                    return response.blob();
                })
                .then(blob => {
                    if (fileInput.files?.length) return;
                    revoke();
                    objectUrl = URL.createObjectURL(blob);
                    let savedCrop = null;
                    try { savedCrop = JSON.parse(root.dataset.initialCrop || 'null'); }
                    catch { /* A missing saved position uses the centred image. */ }
                    load(objectUrl, null, savedCrop);
                })
                .catch(error => message(error.message, true));
        }
    }

    function init() {
        document.querySelectorAll('[data-entity-banner-editor]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('livewire:navigated', init);
    if (document.body) {
        // The same editor also appears in Filament relation-manager dialogs.
        const observer = new MutationObserver(init);
        observer.observe(document.body, {childList:true, subtree:true});
    }
})();
