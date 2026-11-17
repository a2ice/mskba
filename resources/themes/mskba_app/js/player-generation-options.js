import { setupPlayerUniformSelection } from './player-generation-uniform-selection.js';

const workspace = document.querySelector('[data-player-workspace]');
if (workspace) {
    const billing = workspace.querySelector('[data-player-billing-dialog]');
    workspace.querySelector('[data-player-billing-open]')?.addEventListener('click', () => billing?.showModal());
    billing?.querySelector('[data-player-billing-close]')?.addEventListener('click', () => billing.close());

    // Saved, private face references are independent of paid avatar generation.
    // The green check means THIS exact media ID was used in a completed generation,
    // never that a file was merely selected or uploaded.
    const faceTiles = [...workspace.querySelectorAll('[data-face-upload]')];
    const faceGroupStatus = workspace.querySelector('[data-player-face-group-feedback]');
    const faceForm = workspace.querySelector('[data-player-physical-form]');
    const faceCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let activeFaceMutations = 0;
    let faceSnapshotVersion = 0;
    // Upload messages belong to the photo area, never the paid generation status.
    const faceFeedback = (tile, text, error = false) => {
        const status = tile?.querySelector('[data-face-feedback]') || faceGroupStatus;
        if (!status) return;
        status.textContent = text;
        status.hidden = !text;
        status.classList.toggle('is-error', error);
        status.setAttribute('role', error ? 'alert' : 'status');
    };
    const faceRequest = async (mutation, fields = {}) => {
        const payload = new FormData();
        payload.set('_method', 'PATCH');
        payload.set('_token', faceCsrf);
        payload.set('mutation', mutation);
        Object.entries(fields).forEach(([key,value]) => payload.set(key,value));
        let response;
        try {
            response = await fetch(faceForm.action, {
                method:'POST', credentials:'same-origin',
                headers:{Accept:'application/json','X-CSRF-TOKEN':faceCsrf},
                body:payload,
            });
        } catch {
            throw new Error('Не удалось соединиться с сервером. Проверьте подключение к интернету.');
        }
        const json = await response.json().catch(() => null);
        if (!response.ok) {
            const detail = json?.errors ? Object.values(json.errors).flat()[0] : null;
            throw new Error(detail || json?.message || ({
                413:'Файл слишком большой.',
                419:'Сессия истекла. Обновите страницу.',
                429:'Слишком много запросов. Повторите попытку позже.',
            }[response.status] || 'Не удалось сохранить фотографию (HTTP ' + response.status + ').'));
        }
        if (!json || typeof json !== 'object') throw new Error('Сервер вернул некорректный ответ.');
        return json;
    };
    const renderFaceReference = (tile, reference) => {
        const image = tile.querySelector('[data-face-photo]');
        const filename = tile.querySelector('[data-face-filename]');
        const remove = tile.querySelector('[data-face-delete]');
        const hasPhoto = !!reference?.id;
        tile.classList.toggle('has-file', hasPhoto);
        tile.classList.toggle('is-verified', hasPhoto && reference.verified === true);
        if (remove) remove.hidden = !hasPhoto;
        if (filename) filename.textContent = hasPhoto ? 'Заменить фото' : 'Добавить фото';
        if (image) {
            // The private endpoint is on this origin; never display a foreign URL.
            let url = null;
            if (hasPhoto && typeof reference.url === 'string') {
                try {
                    const candidate = new URL(reference.url, location.origin);
                    if (candidate.origin === location.origin) url = candidate.href;
                } catch { /* Fall back to the silhouette. */ }
            }
            image.src = url || image.dataset.facePlaceholder;
        }
    };
    const renderFaceReferences = references => {
        for (const tile of faceTiles) {
            if (tile.classList.contains('is-uploading')) continue;
            renderFaceReference(tile, references?.[tile.dataset.faceUpload] || null);
        }
    };
    const refreshFaceReferences = async () => {
        if (!faceForm || !faceTiles.length) return;
        const version = faceSnapshotVersion;
        const response = await faceRequest('generation_preferences');
        if (version === faceSnapshotVersion) {
            renderFaceReferences(response.face_references || {});
            faceFeedback(null, '');
        }
    };
    const runFaceMutation = async (tile, mutation, fields) => {
        if (tile.classList.contains('is-uploading')) return;
        const action = mutation === 'face_reference' ? 'Сохраняем фотографию…' : 'Удаляем фотографию…';
        tile.classList.add('is-uploading');
        faceSnapshotVersion++;
        activeFaceMutations++;
        workspace.dataset.playerFaceUploading = 'true';
        faceFeedback(tile, action);
        try {
            const result = await faceRequest(mutation, fields);
            if (mutation === 'face_reference') {
                renderFaceReference(tile, {
                    id:Number(result.media_id),
                    url:result.preview_url,
                    verified:false,
                });
                faceFeedback(tile, 'Фото сохранено.');
            } else {
                renderFaceReference(tile, null);
                faceFeedback(tile, 'Фото удалено.');
            }
        } catch (error) {
            faceFeedback(tile, error.message || 'Не удалось изменить фотографию.', true);
        } finally {
            tile.classList.remove('is-uploading');
            activeFaceMutations--;
            workspace.dataset.playerFaceUploading = activeFaceMutations > 0 ? 'true' : 'false';
            tile.querySelector('[data-player-face-slot]').value = '';
        }
    };
    for (const tile of faceTiles) {
        const slot = tile.dataset.faceUpload;
        const input = tile.querySelector('[data-player-face-slot]');
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                faceFeedback(tile, 'Фотография должна быть не больше 5 МБ.', true);
                input.value = '';
                return;
            }
            if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
                faceFeedback(tile, 'Поддерживаются только JPG, PNG и WebP.', true);
                input.value = '';
                return;
            }
            void runFaceMutation(tile, 'face_reference', {face_reference_slot:slot,face_reference:file});
        });
        tile.querySelector('[data-face-delete]')?.addEventListener('click', () =>
            void runFaceMutation(tile, 'face_reference_delete', {face_reference_slot:slot}));
    }
    workspace.addEventListener('mskba:avatar-generated', () => {
        void refreshFaceReferences().catch(() => {});
    });
    // First paint already includes private saved references and verified states
    // from Blade. Re-fetch only after mutations/generation, not on every reload.

    // The selected uniform is the single source for branding and avatar generation.
    setupPlayerUniformSelection(workspace);
}
