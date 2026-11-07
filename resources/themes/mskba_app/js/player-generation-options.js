import { resolvePlayerLogoPreview, resolvePlayerTeamLogoPreview } from './player-generation-logo-state.js';
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
    void refreshFaceReferences().catch(() => {
        faceFeedback(null, 'Не удалось загрузить сохранённые фотографии. Обновите страницу.', true);
    });

    const uniform = workspace.querySelector('[data-player-generation-team]');
    const uniformSource = workspace.querySelector('[data-player-uniform-source]');
    const logoControl = workspace.querySelector('[data-player-logo-control]');

    const logoDialog = workspace.querySelector('[data-player-logo-dialog]');
    const logoCheckbox = workspace.querySelector('[data-player-logo-enabled]');
    const logoOptions = workspace.querySelector('[data-player-logo-options]');
    const picker = workspace.querySelector('[data-player-logo-picker-open]');
    const preview = workspace.querySelector('[data-player-logo-preview]');
    const previewButton = workspace.querySelector('[data-player-logo-preview-open]');
    const viewDialog = workspace.querySelector('[data-player-logo-view-dialog]');
    const viewImage = viewDialog?.querySelector('[data-player-logo-view-image]');
    const selectedText = workspace.querySelector('[data-player-logo-selected]');
    const status = logoDialog?.querySelector('[data-player-logo-status]');
    const gallery = logoDialog?.querySelector('[data-player-logo-gallery]');
    const dropzone = logoDialog?.querySelector('[data-player-logo-dropzone]');
    const upload = logoDialog?.querySelector('[data-player-logo-upload]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let selectedLogoId = Number(logoOptions?.dataset.savedLogoId || 0) || null;
    let logoMode = logoOptions?.dataset.savedLogoMode === 'custom' ? 'custom' : 'team';
    let logos = [];
    const enabled = () => logoCheckbox?.checked === true;
    const setStatus = text => { if (status) status.textContent = text; };
    const placeholder = preview?.dataset.logoPlaceholder || '';
    const selectedTeamLogo = () => {
        const option = uniformSource?.value === 'team' ? uniform?.selectedOptions?.[0] : null;
        return resolvePlayerTeamLogoPreview({
            id: Number(option?.value || 0),
            name: option?.dataset.teamName || option?.textContent?.trim() || '',
            logo_url: option?.dataset.teamLogoUrl || '',
        }, window.location.origin, placeholder);
    };
    const currentLogo = () => logoMode === 'custom'
        ? resolvePlayerLogoPreview(logos, selectedLogoId, window.location.origin, placeholder)
        : selectedTeamLogo();
    const refreshMode = () => {
        const isSavedUniform = uniformSource?.value === 'mine';
        const withLogo = enabled() && !isSavedUniform;
        if (logoControl) logoControl.hidden = isSavedUniform;
        if (logoOptions) logoOptions.hidden = !withLogo;
        const image = currentLogo();
        if (preview) {
            preview.src = image.src;
            preview.alt = image.alt;
        }
        if (previewButton) previewButton.disabled = !withLogo || !image.src || image.src === placeholder;
        if (selectedText) {
            selectedText.textContent = image.label;
            selectedText.title = image.label;
        }
        // A team crest only exists with a selected team. Saved uniforms own
        // their own logo and bypass this independent override control.
        workspace.dataset.playerGenerationLogoMode = withLogo && (
            logoMode === 'custom' || (logoMode === 'team' && image.src !== placeholder)
        ) ? logoMode : 'none';
        workspace.dataset.playerGenerationCustomLogoId = withLogo && logoMode === 'custom' && selectedLogoId
            ? String(selectedLogoId) : '';
    };
    logoCheckbox?.addEventListener('change', refreshMode);
    previewButton?.addEventListener('click', () => {
        const image = currentLogo();
        if (!enabled() || !image.src || image.src === placeholder || !viewDialog || !viewImage) return;
        viewImage.src = image.src;
        viewDialog.showModal();
        viewDialog.querySelector('h2[tabindex="-1"]')?.focus({preventScroll:true});
    });
    viewDialog?.querySelector('[data-player-logo-view-close]')?.addEventListener('click', () => viewDialog.close());
    viewDialog?.addEventListener('close', () => {
        if (viewImage) viewImage.removeAttribute('src');
        previewButton?.focus({preventScroll:true});
    });
    viewDialog?.addEventListener('click', event => {
        if (event.target === viewDialog) viewDialog.close();
    });
    const asJson = async response => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Не удалось выполнить операцию.');
        return data;
    };
    const renderGallery = () => {
        if (!gallery) return;
        gallery.replaceChildren();
        const teamImage = selectedTeamLogo();
        if (teamImage.src && teamImage.src !== placeholder) {
            const cell = document.createElement('div');
            cell.className = 'app-player-workspace__logo-item app-player-workspace__logo-item--team';
            const choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'app-player-workspace__logo-choice';
            choose.setAttribute('aria-pressed', String(logoMode === 'team'));
            const img = document.createElement('img');
            img.src = teamImage.src;
            img.alt = 'Логотип команды';
            const name = document.createElement('span');
            name.textContent = 'Логотип команды';
            choose.append(img, name);
            choose.addEventListener('click', () => {
                logoMode = 'team';
                refreshMode();
                logoDialog.close();
            });
            cell.append(choose);
            gallery.append(cell);
        }
        logos.forEach(logo => {
            const cell = document.createElement('div');
            cell.className = 'app-player-workspace__logo-item';
            const choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'app-player-workspace__logo-choice';
            choose.setAttribute('aria-pressed', String(logoMode === 'custom' && Number(logo.id) === selectedLogoId));
            const img = document.createElement('img');
            img.src = logo.url;
            img.alt = logo.name || 'Логотип';
            choose.append(img);
            const name = document.createElement('span');
            name.textContent = logo.name || 'Логотип';
            choose.append(name);
            choose.addEventListener('click', () => {
                logoMode = 'custom';
                selectedLogoId = Number(logo.id);
                refreshMode();
                logoDialog.close();
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'app-player-workspace__logo-delete';
            remove.textContent = 'Удалить';
            remove.setAttribute('aria-label', 'Удалить логотип ' + (logo.name || logo.id));
            remove.addEventListener('click', async () => {
                try {
                    await asJson(await fetch(logoDialog.dataset.listUrl + '/' + logo.id, {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                    }));
                    if (selectedLogoId === Number(logo.id)) {
                        selectedLogoId = null;
                        if (logoMode === 'custom') logoMode = 'team';
                    }
                    await loadLogos();
                    refreshMode();
                    setStatus('Логотип удалён.');
                } catch (error) { setStatus(error.message); }
            });
            cell.append(choose, remove);
            gallery.append(cell);
        });
        if (!gallery.children.length) {
            const empty = document.createElement('p');
            empty.textContent = 'Логотипов пока нет.';
            gallery.append(empty);
        }
        if (dropzone) dropzone.hidden = logos.length >= 3;
    };
    async function loadLogos() {
        const data = await asJson(await fetch(logoDialog.dataset.listUrl, {
            credentials: 'same-origin', headers: {Accept: 'application/json'},
        }));
        logos = Array.isArray(data.logos) ? data.logos : [];
        if (selectedLogoId !== null && !logos.some(logo => Number(logo.id) === selectedLogoId)) {
            selectedLogoId = null; // The saved logo may have been deleted elsewhere.
            if (logoMode === 'custom') logoMode = 'team';
        }
        renderGallery();
        refreshMode();
    }
    async function openPicker() {
        if (!logoDialog || logoDialog.open) return;
        logoDialog.showModal();
        setStatus('Загружаем список логотипов…');
        try { await loadLogos(); setStatus(''); }
        catch (error) { setStatus(error.message); }
    }
    picker?.addEventListener('click', openPicker);
    logoDialog?.querySelector('[data-player-logo-close]')?.addEventListener('click', () => logoDialog.close());
    logoDialog?.addEventListener('close', () => picker?.focus({preventScroll:true}));
    async function uploadLogo(file) {
        if (!file) return;
        if (logos.length >= 3) { setStatus('Достигнут лимит трёх логотипов.'); return; }
        const data = new FormData();
        data.set('logo', file);
        setStatus('Сохраняем логотип…');
        try {
            const payload = await asJson(await fetch(logoDialog.dataset.uploadUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}, body: data,
            }));
            selectedLogoId = Number(payload.logo.id);
            logoMode = 'custom';
            await loadLogos();
            refreshMode();
            setStatus('Логотип загружен и выбран.');
        } catch (error) { setStatus(error.message); }
        finally { if (upload) upload.value = ''; }
    }
    upload?.addEventListener('change', () => uploadLogo(upload.files?.[0]));
    dropzone?.addEventListener('dragover', event => {
        event.preventDefault();
        dropzone.classList.add('is-dragover');
    });
    dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
    dropzone?.addEventListener('drop', event => {
        event.preventDefault();
        dropzone.classList.remove('is-dragover');
        uploadLogo(event.dataTransfer?.files?.[0]);
    });
    dropzone?.addEventListener('keydown', event => {
        if (event.target !== dropzone) return;
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); upload?.click(); }
    });
    setupPlayerUniformSelection(workspace, () => {
        refreshMode();
        renderGallery();
    });
    refreshMode();
    // Restore the last selected private logo; the team logo is available
    // immediately from the authorized selected team option, without a placeholder.
    if (enabled() && logoMode === 'custom' && selectedLogoId !== null) {
        void loadLogos().catch(() => { /* Retry through the chooser. */ });
    }
}
