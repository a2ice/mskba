const workspace = document.querySelector('[data-player-workspace]');
if (workspace) {
    const billing = workspace.querySelector('[data-player-billing-dialog]');
    workspace.querySelector('[data-player-billing-open]')?.addEventListener('click', () => billing?.showModal());
    billing?.querySelector('[data-player-billing-close]')?.addEventListener('click', () => billing.close());

    // Face inputs remain ordinary multipart file inputs. Only their presentation
    // changes: the server continues validating the actual file and camera angle.
    workspace.querySelectorAll('[data-face-upload]').forEach(tile => {
        const input = tile.querySelector('[data-player-face-slot]');
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            tile.classList.toggle('has-file', Boolean(file));
            const label = tile.querySelector('[data-face-filename]');
            if (label) label.textContent = file ? file.name : 'Добавить фото';
        });
    });

    const uniform = workspace.querySelector('[data-player-generation-team]');
    const uniformDialog = workspace.querySelector('[data-player-uniform-dialog]');
    const firstTeam = Array.from(uniform?.options || []).find(option => option.value !== '__custom__')?.value || '';
    uniform?.addEventListener('change', () => {
        if (uniform.value !== '__custom__') return;
        // "Create custom uniform" must never be submitted as a team ID.
        uniform.value = firstTeam;
        uniformDialog?.showModal();
    });
    uniformDialog?.querySelector('[data-player-uniform-close]')?.addEventListener('click', () => uniformDialog.close());

    const logoDialog = workspace.querySelector('[data-player-logo-dialog]');
    const logoOptions = workspace.querySelector('[data-player-logo-options]');
    const picker = workspace.querySelector('[data-player-logo-picker-open]');
    const selectedText = workspace.querySelector('[data-player-logo-selected]');
    const status = logoDialog?.querySelector('[data-player-logo-status]');
    const gallery = logoDialog?.querySelector('[data-player-logo-gallery]');
    const dropzone = logoDialog?.querySelector('[data-player-logo-dropzone]');
    const upload = logoDialog?.querySelector('[data-player-logo-upload]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let selectedLogoId = null;
    let logos = [];
    const enabled = () => workspace.querySelector('[data-player-logo-enabled]:checked')?.value === 'yes';
    const source = () => workspace.querySelector('[data-player-logo-source]:checked')?.value || 'team';
    const setStatus = text => { if (status) status.textContent = text; };
    const refreshMode = () => {
        if (logoOptions) logoOptions.hidden = !enabled();
        if (picker) picker.hidden = !enabled() || source() !== 'custom';
        if (selectedText) selectedText.textContent = enabled() && source() === 'custom'
            ? (logos.find(logo => logo.id === selectedLogoId)?.name || 'Логотип не выбран')
            : '';
        workspace.dataset.playerGenerationLogoMode = !enabled() ? 'none' : source();
        workspace.dataset.playerGenerationCustomLogoId = selectedLogoId ? String(selectedLogoId) : '';
    };
    workspace.querySelectorAll('[data-player-logo-enabled],[data-player-logo-source]').forEach(input => {
        input.addEventListener('change', () => {
            refreshMode();
            if (enabled() && source() === 'custom' && selectedLogoId === null) openPicker();
        });
    });
    const asJson = async response => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Не удалось выполнить операцию.');
        return data;
    };
    const renderGallery = () => {
        if (!gallery) return;
        gallery.replaceChildren();
        logos.forEach(logo => {
            const cell = document.createElement('div');
            cell.className = 'app-player-workspace__logo-item';
            const choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'app-player-workspace__logo-choice';
            choose.setAttribute('aria-pressed', String(logo.id === selectedLogoId));
            const img = document.createElement('img');
            img.src = logo.url;
            img.alt = logo.name || 'Логотип';
            choose.append(img);
            const name = document.createElement('span');
            name.textContent = logo.name || 'Логотип';
            choose.append(name);
            choose.addEventListener('click', () => {
                selectedLogoId = logo.id;
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
                    if (selectedLogoId === logo.id) selectedLogoId = null;
                    await loadLogos();
                    refreshMode();
                    setStatus('Логотип удалён.');
                } catch (error) { setStatus(error.message); }
            });
            cell.append(choose, remove);
            gallery.append(cell);
        });
        if (!logos.length) {
            const empty = document.createElement('p');
            empty.textContent = 'Сохранённых логотипов пока нет.';
            gallery.append(empty);
        }
        if (dropzone) dropzone.hidden = logos.length >= 3;
    };
    async function loadLogos() {
        const data = await asJson(await fetch(logoDialog.dataset.listUrl, {
            credentials: 'same-origin', headers: {Accept: 'application/json'},
        }));
        logos = Array.isArray(data.logos) ? data.logos : [];
        renderGallery();
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
            selectedLogoId = payload.logo.id;
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
    refreshMode();
}
