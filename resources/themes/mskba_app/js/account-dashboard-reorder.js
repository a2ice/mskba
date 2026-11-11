import { reorderDashboardWidgets } from './dashboard-widget-order.js';

// Per-user grid customization. Fixed widgets are never draggable and keep
// their exact grid slots. Native drag/drop + accessible up/down controls.
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

    const widgets = () => Array.from(dashboard.querySelectorAll('[data-dashboard-widget]'));
    const slots = () => widgets().map((node) => ({
        id: Number(node.dataset.dashboardWidget),
        canMove: node.dataset.widgetCanMove === '1',
    }));
    const ids = () => slots().map((widget) => widget.id);
    const showStatus = (message) => { if (status) status.textContent = message; };
    const movable = (node) => node?.dataset.widgetCanMove === '1';

    const updateArrows = () => {
        const orderedMovable = widgets().filter(movable);
        orderedMovable.forEach((node, index) => {
            const up = node.querySelector('[data-dashboard-move="up"]');
            const down = node.querySelector('[data-dashboard-move="down"]');
            if (up) up.disabled = saving || index === 0;
            if (down) down.disabled = saving || index === orderedMovable.length - 1;
        });
    };

    const applyIds = (order) => {
        const current = new Map(widgets().map((node) => [Number(node.dataset.dashboardWidget), node]));
        if (order.some((id) => !current.has(id))) return;
        // Non-widget children (e.g. a server-owned <style>) are left intact.
        order.forEach((id) => dashboard.appendChild(current.get(id)));
        updateArrows();
    };

    const persist = async (previous) => {
        if (saving || ids().every((id, index) => id === previous[index])) return;
        saving = true;
        if (toggle) toggle.disabled = true;
        updateArrows();
        showStatus('Сохраняем порядок…');

        try {
            const response = await fetch(dashboard.dataset.orderUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf || '',
                },
                body: JSON.stringify({ widget_ids: ids() }),
            });
            if (!response.ok) throw new Error('Widget order rejected');
            showStatus('Порядок сохранён.');
        } catch (_error) {
            applyIds(previous);
            showStatus('Не удалось сохранить порядок. Положение карточек восстановлено.');
        } finally {
            saving = false;
            if (toggle) toggle.disabled = false;
            updateArrows();
        }
    };

    const changeOrder = (source, target) => {
        if (!enabled || saving || !movable(source) || !movable(target)) return;
        const previous = ids();
        const next = reorderDashboardWidgets(
            slots(), Number(source.dataset.dashboardWidget), Number(target.dataset.dashboardWidget)
        );
        if (!next) return;
        applyIds(next);
        void persist(previous);
    };

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (saving) return;
            enabled = !enabled;
            dashboard.dataset.dashboardOrdering = enabled ? '1' : '0';
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.textContent = enabled ? 'Готово' : 'Изменить порядок';
            if (hint) hint.hidden = !enabled;
            if (!enabled) {
                widgets().forEach((node) => node.removeAttribute('draggable'));
                document.dispatchEvent(new Event('mskba:dashboard-refresh-requested'));
            }
            updateArrows();
        });
    }

    document.addEventListener('mskba:dashboard-edit-cancel', () => {
        if (!enabled) return;
        enabled = false;
        dashboard.dataset.dashboardOrdering = '0';
        if (toggle) {
            toggle.setAttribute('aria-pressed', 'false');
            toggle.textContent = 'Изменить порядок';
        }
        if (hint) hint.hidden = true;
        widgets().forEach((node) => node.removeAttribute('draggable'));
        showStatus('Список доступных виджетов изменился. Порядок обновлён.');
    });

    dashboard.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dashboard-move]');
        if (!enabled || saving || !button) return;
        const node = button.closest('[data-dashboard-widget]');
        if (!movable(node)) return;
        const allowed = widgets().filter(movable);
        const at = allowed.indexOf(node);
        const next = button.dataset.dashboardMove === 'up' ? at - 1 : at + 1;
        if (next < 0 || next >= allowed.length) return;
        changeOrder(node, allowed[next]);
    });

    dashboard.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-dashboard-drag-handle]');
        const widget = handle?.closest('[data-dashboard-widget]');
        if (enabled && !saving && movable(widget)) widget.draggable = true;
    });
    dashboard.addEventListener('pointerup', () => {
        if (!dragged) widgets().forEach((node) => node.removeAttribute('draggable'));
    });
    dashboard.addEventListener('dragstart', (event) => {
        const node = event.target.closest('[data-dashboard-widget]');
        if (!enabled || saving || !movable(node) || !node.draggable) {
            event.preventDefault();
            return;
        }
        dragged = node;
        node.classList.add('app-dashboard-widget--dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', node.dataset.dashboardWidget);
    });
    dashboard.addEventListener('dragover', (event) => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!movable(target) || target === dragged) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        widgets().forEach((node) => node.classList.toggle('app-dashboard-widget--drag-target', node === target));
    });
    dashboard.addEventListener('drop', (event) => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!movable(target) || target === dragged) return;
        event.preventDefault();
        changeOrder(dragged, target);
    });
    dashboard.addEventListener('dragend', () => {
        dragged?.classList.remove('app-dashboard-widget--dragging');
        dragged = null;
        originalIds = [];
        widgets().forEach((node) => {
            node.removeAttribute('draggable');
            node.classList.remove('app-dashboard-widget--drag-target');
        });
    });
    updateArrows();
}
