// Player-avatar UI adapter around the existing, server-owned uniform projects.
// The workshop remains reusable and never changes navigation outside this context.
export function setupPlayerUniformSelection(workspace, onChanged = () => {}) {
    const source = workspace.querySelector('[data-player-uniform-source]');
    const team = workspace.querySelector('[data-player-generation-team]');
    const design = workspace.querySelector('[data-player-generation-uniform-design]');
    const teamField = workspace.querySelector('[data-player-uniform-team-field]');
    const designField = workspace.querySelector('[data-player-uniform-design-field]');
    if (!source || !team || !design) return;

    const mineOption = source.querySelector('option[value="mine"]');
    let creating = false;
    const setMode = () => {
        const mode = source.value;
        const teamVisible = mode === 'team';
        const mineVisible = mode === 'mine';
        if (teamField) teamField.hidden = !teamVisible;
        if (designField) designField.hidden = !mineVisible;
        team.disabled = !teamVisible;
        design.disabled = !mineVisible;
        if (mode === 'neutral') {
            team.value = '';
            design.value = '';
        }
        workspace.dataset.playerGenerationUniformSource = mode;
        onChanged();
    };

    source.addEventListener('change', () => {
        if (source.value === '__create__') {
            // Uncommitted designs never become a selected uniform.
            source.value = 'neutral';
            setMode();
            creating = true;
            window.dispatchEvent(new CustomEvent('mskba:uniform:open', {
                detail: { context: 'player-avatar' },
            }));
            return;
        }
        if (source.value === 'mine' && mineOption?.disabled) source.value = 'neutral';
        if (source.value === 'team' && !team.querySelector('option[value]:not([value=""])')) {
            source.value = 'neutral';
        }
        setMode();
    });
    team.addEventListener('change', onChanged);
    design.addEventListener('change', onChanged);

    const replaceDesigns = (designs, targetId) => {
        const records = (Array.isArray(designs) ? designs : [])
            .filter(item => Number.isSafeInteger(Number(item?.id)) && Number(item.id) > 0)
            .map(item => ({ id: String(item.id), name: String(item.name || 'Моя форма') }));
        const previouslySelected = String(targetId || design.value || '');
        design.replaceChildren(new Option('Выберите форму', ''));
        records.forEach(item => design.add(new Option(item.name, item.id)));
        design.value = records.some(item => item.id === previouslySelected) ? previouslySelected : '';
        if (mineOption) {
            mineOption.disabled = records.length === 0;
            mineOption.hidden = records.length === 0;
        }
        return !!design.value;
    };
    const loadDesigns = async targetId => {
        const response = await fetch('/uniforms/projects', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Не удалось обновить список сохранённых форм.');
        const payload = await response.json();
        return replaceDesigns(payload.designs, targetId);
    };

    window.addEventListener('mskba:uniform:saved', async event => {
        if (event.detail?.context !== 'player-avatar') return;
        creating = false;
        const saved = event.detail?.design;
        if (!saved?.id) return;
        try {
            await loadDesigns(saved.id);
        } catch {
            // The POST already succeeded; do not lose the new selection when
            // a secondary list request is temporarily unavailable.
            if (!Array.from(design.options).some(option => option.value === String(saved.id))) {
                design.add(new Option(String(saved.name || 'Моя форма'), String(saved.id)));
            }
            if (mineOption) { mineOption.disabled = false; mineOption.hidden = false; }
        }
        source.value = 'mine';
        design.value = String(saved.id);
        setMode();
    });
    window.addEventListener('mskba:uniform:closed', event => {
        if (event.detail?.context !== 'player-avatar') return;
        if (!event.detail.saved && creating) {
            source.value = 'neutral';
            setMode();
        }
        creating = false;
    });

    setMode();
}
