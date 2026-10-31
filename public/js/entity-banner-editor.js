(() => {
    'use strict';
    if (window.__mskbaBannerEditorInitialized) return;
    window.__mskbaBannerEditorInitialized = true;

    const virtualWidth = 1200;
    const virtualHeight = 400;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    function setup(root) {
        if (root.dataset.bannerReady) return;
        root.dataset.bannerReady = '1';

        const fileInput = root.querySelector('[data-banner-file]');
        const dropzone = root.querySelector('[data-banner-dropzone]');
        const chooseLabel = root.querySelector('[data-banner-choose-label]');
        const canvas = root.querySelector('[data-banner-canvas]');
        const ctx = canvas.getContext('2d');
        const zoomInput = root.querySelector('[data-banner-zoom]');
        const reset = root.querySelector('[data-banner-reset]');
        const save = root.querySelector('[data-banner-save]');
        const remove = root.querySelector('[data-banner-delete]');
        const confirm = root.querySelector('[data-banner-confirm]');
        const confirmDelete = root.querySelector('[data-banner-confirm-delete]');
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
        let imageGeneration = 0;
        let initialCrop;
        try {
            initialCrop = JSON.parse(root.dataset.initialCrop || '{}');
        } catch {
            initialCrop = {};
        }

        function message(text, error = false) {
            status.textContent = text;
            status.classList.toggle('is-error', error);
        }

        function dimensions() {
            if (!image) return null;
            const base = Math.max(virtualWidth / image.naturalWidth, virtualHeight / image.naturalHeight);
            return {
                w: image.naturalWidth * base * zoom,
                h: image.naturalHeight * base * zoom,
            };
        }

        function constrain() {
            const d = dimensions();
            if (!d) return;
            x = clamp(x, virtualWidth - d.w, 0);
            y = clamp(y, virtualHeight - d.h, 0);
        }

        function center() {
            const d = dimensions();
            if (!d) return;
            x = (virtualWidth - d.w) / 2;
            y = (virtualHeight - d.h) / 2;
        }

        function render() {
            if (!ctx) return;
            ctx.clearRect(0, 0, virtualWidth, virtualHeight);
            if (!image) return;
            constrain();
            const d = dimensions();
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(image, x, y, d.w, d.h);
            empty.hidden = true;
            save.disabled = busy;
        }

        function restoreSelection(selection) {
            if (!selection || !image) return;
            const {x: left, y: top, width, height} = selection;
            if (![left, top, width, height].every(Number.isFinite)
                || width <= 0 || height <= 0
                || left < 0 || top < 0 || left + width > 1.001 || top + height > 1.001) return;

            const base = Math.max(virtualWidth / image.naturalWidth, virtualHeight / image.naturalHeight);
            zoom = clamp((virtualWidth / width) / (image.naturalWidth * base), 1, 3);
            zoomInput.value = String(zoom);
            const d = dimensions();
            x = -left * d.w;
            y = -top * d.h;
            constrain();
        }

        function load(source, selectedFile = null, selection = null, quiet = false) {
            const generation = ++imageGeneration;
            const next = new Image();
            next.onload = () => {
                if (generation !== imageGeneration) return;
                image = next;
                file = selectedFile;
                zoom = 1;
                zoomInput.value = '1';
                center();
                restoreSelection(selection);
                render();
                if (!quiet) message('Выберите нужный фрагмент изображения.');
            };
            next.onerror = () => {
                if (generation === imageGeneration) message('Не удалось открыть изображение.', true);
            };
            next.src = source;
        }

        function revoke() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        function selectFile(candidate) {
            if (!candidate || busy) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(candidate.type)
                || candidate.size > 10 * 1024 * 1024) {
                message('Выберите изображение JPG, PNG или WebP размером до 10 МБ.', true);
                fileInput.value = '';
                return;
            }
            revoke();
            objectUrl = URL.createObjectURL(candidate);
            load(objectUrl, candidate);
        }

        fileInput.addEventListener('change', () => selectFile(fileInput.files?.[0]));

        for (const name of ['dragenter', 'dragover']) {
            dropzone.addEventListener(name, event => {
                event.preventDefault();
                if (!busy) dropzone.classList.add('is-dragover');
            });
        }
        for (const name of ['dragleave', 'dragend']) {
            dropzone.addEventListener(name, () => dropzone.classList.remove('is-dragover'));
        }
        dropzone.addEventListener('drop', event => {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');
            selectFile(event.dataTransfer?.files?.[0]);
        });

        zoomInput.addEventListener('input', () => {
            if (!image || busy) return;
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
            if (!image || busy) return;
            zoom = 1;
            zoomInput.value = '1';
            center();
            render();
            message('Изображение выровнено по центру.');
        });

        canvas.addEventListener('pointerdown', event => {
            if (!image || busy || (event.pointerType === 'mouse' && event.button !== 0)) return;
            dragging = {id:event.pointerId, x:event.clientX, y:event.clientY, left:x, top:y};
            canvas.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        canvas.addEventListener('pointermove', event => {
            if (!dragging || dragging.id !== event.pointerId) return;
            const bounds = canvas.getBoundingClientRect();
            x = dragging.left + (event.clientX - dragging.x) * virtualWidth / bounds.width;
            y = dragging.top + (event.clientY - dragging.y) * virtualHeight / bounds.height;
            render();
        });

        for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            canvas.addEventListener(name, () => { dragging = null; });
        }

        canvas.addEventListener('keydown', event => {
            if (!image || busy) return;
            const move = event.shiftKey ? 48 : 12;
            switch (event.key) {
                case 'ArrowLeft': x += move; break;
                case 'ArrowRight': x -= move; break;
                case 'ArrowUp': y += move; break;
                case 'ArrowDown': y -= move; break;
                default: return;
            }
            event.preventDefault();
            render();
        });

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
            root.setAttribute('aria-busy', String(next));
            save.disabled = next || !image;
            remove.disabled = next;
            reset.disabled = next;
            fileInput.disabled = next;
            zoomInput.disabled = next;
            confirmDelete.disabled = next;
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
                chooseLabel.textContent = 'Заменить изображение';
                file = null;
                fileInput.value = '';
                root.dataset.initialCrop = JSON.stringify(selection);
                root.dataset.originalUrl ||= root.dataset.uploadUrl.replace(/\/$/, '') + '/source';
                // Recover the saved original so further positioning remains lossless.
                try {
                    const original = await fetch(root.dataset.originalUrl, {credentials:'same-origin'});
                    if (original.ok) {
                        const source = URL.createObjectURL(await original.blob());
                        revoke();
                        objectUrl = source;
                        load(objectUrl, null, selection, true);
                    }
                } catch {
                    // The current preview is still valid and the save completed.
                }
                message(data.message || 'Баннер сохранён.');
            } catch (error) {
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        remove.addEventListener('click', () => {
            if (!busy) confirm.showModal();
        });

        root.querySelectorAll('[data-banner-cancel]').forEach(button => {
            button.addEventListener('click', () => confirm.close());
        });
        confirm.addEventListener('close', () => {
            if (remove.hidden === false) remove.focus();
        });

        confirmDelete.addEventListener('click', async () => {
            if (busy) return;
            setBusy(true);
            message('Удаляем баннер…');
            try {
                const data = await request(root.dataset.deleteUrl, 'DELETE');
                confirm.close();
                saved.hidden = true;
                published.removeAttribute('src');
                remove.hidden = true;
                chooseLabel.textContent = 'Выбрать изображение';
                fileInput.value = '';
                file = null;
                image = null;
                ++imageGeneration;
                revoke();
                root.dataset.originalUrl = '';
                root.dataset.initialCrop = '{}';
                zoom = 1;
                zoomInput.value = '1';
                ctx?.clearRect(0, 0, virtualWidth, virtualHeight);
                empty.hidden = false;
                message(data.message || 'Баннер удалён.');
            } catch (error) {
                confirm.close();
                message(error.message, true);
            } finally {
                setBusy(false);
            }
        });

        if (root.dataset.originalUrl) {
            fetch(root.dataset.originalUrl, {credentials:'same-origin'})
                .then(response => {
                    if (!response.ok) throw new Error('Не удалось открыть сохранённое изображение.');
                    return response.blob();
                })
                .then(blob => {
                    objectUrl = URL.createObjectURL(blob);
                    load(objectUrl, null, initialCrop);
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
        const observer = new MutationObserver(init);
        observer.observe(document.body, {childList:true, subtree:true});
    }
})();
