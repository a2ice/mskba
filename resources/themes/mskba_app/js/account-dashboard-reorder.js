// Account dashboard order is personal, not an administrator's global sort order.
// Native HTML drag/drop on desktop; keyboard/touch fallback via up/down buttons.
const dashboard = document.querySelector('[data-account-dashboard][data-order-url]');
const controls = document.querySelector('[data-dashboard-customize]');

if (dashboard && controls) {
    const toggle = controls.querySelector('[data-dashboard-order-toggle]');
    const hint = controls.querySelector('[data-dashboard-order-hint]');
    const status = controls.querySelector('[data-dashboard-order-status]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let enabled = false;
    let saving = false;
    let dragged = null;
    let originalIds = [];

    const widgets = () => Array.from(dashboard.querySelectorAll('[data-dashboard-widget]'));
    const ids = () => widgets().map((node) => Number(node.dataset.dashboardWidget));
    const showStatus = (message) => { status.textContent = message; };

    const updateArrows = () => {
        const all = widgets();
        all.forEach((node, index) => {
            const up = node.querySelector('[data-dashboard-move="up"]');
            const down = node.querySelector('[data-dashboard-move="down"]');
            if (up) up.disabled = saving || index === 0;
            if (down) down.disabled = saving || index === all.length - 1;
        });
    };

    const restore = (previous) => {
        const byId = new Map(widgets().map((node) => [Number(node.dataset.dashboardWidget), node]));
        previous.forEach((id) => { if (byId.has(id)) dashboard.appendChild(byId.get(id)); });
        updateArrows();
    };

    const persist = async (previous) => {
        if (saving || ids().every((id, index) => id === previous[index])) return;
        saving = true;
        toggle.disabled = true;
        updateArrows();
        showStatus('Сохраняем порядок…');

        try {
            const response = await fetch(dashboard.dataset.orderUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf || '',
                },
                body: JSON.stringify({ widget_ids: ids() }),
            });
            if (!response.ok) throw new Error('save failed');
            showStatus('Порядок сохранён.');
        } catch (_error) {
            restore(previous);
            showStatus('Не удалось сохранить порядок. Положение карточек восстановлено.');
        } finally {
            saving = false;
            toggle.disabled = false;
            updateArrows();
        }
    };

    toggle.addEventListener('click', () => {
        if (saving) return;
        enabled = !enabled;
        dashboard.dataset.dashboardOrdering = enabled ? '1' : '0';
        toggle.setAttribute('aria-pressed', String(enabled));
        toggle.textContent = enabled ? 'Готово' : 'Изменить порядок';
        hint.hidden = !enabled;
        if (!enabled) {
            widgets().forEach((node) => node.removeAttribute('draggable'));
            document.dispatchEvent(new Event('mskba:dashboard-refresh-requested'));
        }
        updateArrows();
    });

    dashboard.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dashboard-move]');
        if (!enabled || saving || !button) return;
        const node = button.closest('[data-dashboard-widget]');
        if (!node) return;
        const previous = ids();
        if (button.dataset.dashboardMove === 'up') {
            const before = node.previousElementSibling;
            if (!before?.matches('[data-dashboard-widget]')) return;
            dashboard.insertBefore(node, before);
        } else {
            const after = node.nextElementSibling;
            if (!after?.matches('[data-dashboard-widget]')) return;
            dashboard.insertBefore(after, node);
        }
        updateArrows();
        void persist(previous);
    });

    dashboard.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-dashboard-drag-handle]');
        if (enabled && !saving && handle) {
            handle.closest('[data-dashboard-widget]')?.setAttribute('draggable', 'true');
        }
    });
    dashboard.addEventListener('pointerup', () => {
        if (!dragged) widgets().forEach((node) => node.removeAttribute('draggable'));
    });

    dashboard.addEventListener('dragstart', (event) => {
        const node = event.target.closest('[data-dashboard-widget]');
        if (!enabled || saving || !node?.draggable) {
            event.preventDefault();
            return;
        }
        dragged = node;
        originalIds = ids();
        node.classList.add('app-dashboard-widget--dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', node.dataset.dashboardWidget);
    });
    dashboard.addEventListener('dragover', (event) => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!target || target === dragged) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        widgets().forEach((node) => node.classList.toggle('app-dashboard-widget--drag-target', node === target));
    });
    dashboard.addEventListener('drop', (event) => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!target || target === dragged) return;
        event.preventDefault();
        const all = widgets();
        const forward = all.indexOf(dragged) < all.indexOf(target);
        dashboard.insertBefore(dragged, forward ? target.nextSibling : target);
        widgets().forEach((node) => node.classList.remove('app-dashboard-widget--drag-target'));
        updateArrows();
        void persist(originalIds);
    });
    dashboard.addEventListener('dragend', () => {
        dragged?.classList.remove('app-dashboard-widget--dragging');
        dragged = null;
        widgets().forEach((node) => {
            node.removeAttribute('draggable');
            node.classList.remove('app-dashboard-widget--drag-target');
        });
    });
    updateArrows();
}
