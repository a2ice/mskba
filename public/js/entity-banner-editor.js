(() => {
    'use strict';

    const virtualWidth = 1200;
    const virtualHeight = 400;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    function setup(root) {
        if (root.dataset.bannerReady) return;
        root.dataset.bannerReady = '1';

        const fileInput = root.querySelector('[data-banner-file]');
        const canvas = root.querySelector('[data-banner-canvas]');
        const ctx = canvas.getContext('2d');
        const zoomInput = root.querySelector('[data-banner-zoom]');
        const reset = root.querySelector('[data-banner-reset]');
        const save = root.querySelector('[data-banner-save]');
        const remove = root.querySelector('[data-banner-delete]');
        const saved = root.querySelector('[data-banner-saved]');
        const published = root.querySelector('[data-banner-published]');
        const empty = root.querySelector('[data-banner-empty]');
        const status = root.querySelector('[data-banner-status]');
        let image = null;
        let file = null;
        let objectUrl = null;
        let busy = false;
        let zoom = 1;
        let x = 0;
        let y = 0;
        let dragging = null;

        function message(text, error = false) {
            status.textContent = text;
            status.classList.toggle('is-error', error);
        }

        function dimensions() {
            if (!image) return null;
            const base = Math.max(virtualWidth / image.naturalWidth, virtualHeight / image.naturalHeight);
            const w = image.naturalWidth * base * zoom;
            const h = image.naturalHeight * base * zoom;
            return {w, h};
        }

        function center() {
            const d = dimensions();
            if (!d) return;
            x = (virtualWidth - d.w) / 2;
            y = (virtualHeight - d.h) / 2;
        }

        function constrain() {
            const d = dimensions();
            if (!d) return;
            x = clamp(x, virtualWidth - d.w, 0);
            y = clamp(y, virtualHeight - d.h, 0);
        }

        function render() {
            if (!ctx) return;
            ctx.clearRect(0, 0, virtualWidth, virtualHeight);
            if (!image) return;
            constrain();
            const d = dimensions();
            ctx.drawImage(image, x, y, d.w, d.h);
            empty.hidden = true;
            save.disabled = busy;
        }

        function load(source, selectedFile = null) {
            const next = new Image();
            next.onload = () => {
                image = next;
                file = selectedFile;
                zoom = 1;
                zoomInput.value = '1';
                center();
                render();
                message('Перемещайте изображение и настройте масштаб.');
            };
            next.onerror = () => message('Не удалось прочитать исходное изображение.', true);
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
                message('JPEG, PNG или WebP, максимум 10 МБ.', true);
                return;
            }
            revoke();
            objectUrl = URL.createObjectURL(candidate);
            load(objectUrl, candidate);
        });

        zoomInput.addEventListener('input', () => {
            if (!image) return;
            const prev = dimensions();
            const cx = (virtualWidth / 2 - x) / prev.w;
            const cy = (virtualHeight / 2 - y) / prev.h;
            zoom = Number(zoomInput.value);
            const next = dimensions();
            x = virtualWidth / 2 - cx * next.w;
            y = virtualHeight / 2 - cy * next.h;
            render();
        });

        reset.addEventListener('click', () => {
            if (!image) return;
            zoom = 1;
            zoomInput.value = '1';
            center();
            render();
        });

        canvas.addEventListener('pointerdown', (event) => {
            if (!image || busy || (event.pointerType === 'mouse' && event.button !== 0)) return;
            dragging = {id:event.pointerId, x:event.clientX, y:event.clientY, left:x, top:y};
            canvas.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        canvas.addEventListener('pointermove', (event) => {
            if (!dragging || dragging.id !== event.pointerId) return;
            const bounds = canvas.getBoundingClientRect();
            x = dragging.left + (event.clientX - dragging.x) * virtualWidth / bounds.width;
            y = dragging.top + (event.clientY - dragging.y) * virtualHeight / bounds.height;
            render();
        });

        for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            canvas.addEventListener(name, () => { dragging = null; });
        }

        function crop() {
            const d = dimensions();
            if (!d) return null;
            return {
                x: Number(clamp(-x / d.w, 0, 1).toFixed(6)),
                y: Number(clamp(-y / d.h, 0, 1).toFixed(6)),
                width: Number(clamp(virtualWidth / d.w, 0, 1).toFixed(6)),
                height: Number(clamp(virtualHeight / d.h, 0, 1).toFixed(6)),
            };
        }

        function setBusy(next) {
            busy = next;
            save.disabled = next || !image;
            remove.disabled = next;
            reset.disabled = next;
            fileInput.disabled = next;
            zoomInput.disabled = next;
        }

        async function request(url, method, form = null) {
            const response = await fetch(url, {
                method,
                body: form,
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': root.querySelector('[data-banner-csrf]').value,
                    'Accept': 'application/json',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const first = Object.values(payload.errors || {})[0];
                throw new Error(first?.[0] || payload.message || 'Не удалось сохранить баннер.');
            }
            return payload;
        }

        save.addEventListener('click', async () => {
            if (!image || busy) return;
            const selection = crop();
            const form = new FormData();
            if (file) form.append('image', file);
            for (const [key, value] of Object.entries(selection)) {
                form.append('crop[' + key + ']', String(value));
            }

            setBusy(true);
            message('Сохраняем баннер…');
            try {
                const data = await request(root.dataset.uploadUrl, 'POST', form);
                saved.hidden = false;
                published.src = data.url + '?v=' + Date.now();
                remove.hidden = false;
                file = null;
                fileInput.value = '';
                message(data.message || 'Баннер сохранён.');
                if (root.dataset.originalUrl) {
                    // A new immutable original is now available to authorized editors.
                    const response = await fetch(root.dataset.originalUrl, {credentials:'same-origin'});
                    if (response.ok) {
                        revoke();
                        objectUrl = URL.createObjectURL(await response.blob());
                        load(objectUrl);
                    }
                } else {
                    // After the first upload, next crop is performed from the stored original.
                    root.dataset.originalUrl = root.dataset.uploadUrl + '/source';
                }
            } catch (error) {
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        remove.addEventListener('click', async () => {
            if (busy || !window.confirm('Удалить баннер? Логотип и остальные изображения сохранятся.')) return;
            setBusy(true);
            try {
                await request(root.dataset.deleteUrl, 'DELETE');
                saved.hidden = true;
                published.removeAttribute('src');
                remove.hidden = true;
                fileInput.value = '';
                file = null;
                image = null;
                revoke();
                ctx?.clearRect(0, 0, virtualWidth, virtualHeight);
                empty.hidden = false;
                message('Баннер удалён.');
            } catch (error) {
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        if (root.dataset.originalUrl) {
            fetch(root.dataset.originalUrl, {credentials:'same-origin'})
                .then(response => {
                    if (!response.ok) throw new Error('Оригинал недоступен.');
                    return response.blob();
                })
                .then(blob => {
                    objectUrl = URL.createObjectURL(blob);
                    load(objectUrl);
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
    // Filament mounts relation-action modals after page load.
    if (document.body) {
        const observer = new MutationObserver(() => init());
        observer.observe(document.body, {childList:true, subtree:true});
    }
})();
