import { reorderDashboardWidgets } from './dashboard-widget-order.js';

// The entire non-interactive widget header is a drag handle, like MSKBA modal
// headers. A fixed (can_move=false) card never becomes a drag source or target.
const dashboard = document.querySelector('[data-account-dashboard][data-order-url]');
const picker = document.querySelector('[data-dashboard-picker]');

if (dashboard) {
    const status = document.querySelector('[data-dashboard-order-status]');
    const pickerList = picker?.querySelector('[data-dashboard-picker-list]');
    const pickerStatus = picker?.querySelector('[data-dashboard-picker-status]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const interactive = 'button, a, input, select, textarea, label, [role="button"], [contenteditable]';

    let savingOrder = false;
    let savingVisibility = false;
    let dragging = false;
    let dragged = null;
    let touch = null;
    let pickerCatalog = [];

    const widgets = () => Array.from(dashboard.querySelectorAll('[data-dashboard-widget]'));
    const movable = node => node?.dataset.widgetCanMove === '1';
    const ids = () => widgets().map(node => Number(node.dataset.dashboardWidget));
    const slots = () => widgets().map(node => ({
        id: Number(node.dataset.dashboardWidget),
        canMove: movable(node),
    }));

    const showStatus = (message, error = false) => {
        if (!status) return;
        status.textContent = message;
        status.dataset.error = error ? '1' : '0';
    };
    const markEditing = () => {
        dashboard.dataset.dashboardOrdering = dragging || savingOrder ? '1' : '0';
    };
    const highlightTarget = target => {
        widgets().forEach(node => node.classList.toggle(
            'app-dashboard-widget--drag-target', node === target && target !== dragged && movable(target)
        ));
    };
    const applyIds = order => {
        const byId = new Map(widgets().map(node => [Number(node.dataset.dashboardWidget), node]));
        if (order.length !== byId.size || order.some(id => !byId.has(id))) return;
        // Keep the add tile last and preserve server-injected <style> nodes.
        const addTile = dashboard.querySelector('[data-dashboard-add-widget]');
        order.forEach(id => dashboard.insertBefore(byId.get(id), addTile));
    };
    const refresh = () => document.dispatchEvent(new Event('mskba:dashboard-refresh-requested'));

    const saveOrder = async previous => {
        if (savingOrder || ids().every((id, index) => id === previous[index])) return;
        savingOrder = true;
        markEditing();
        showStatus('');
        try {
            const response = await fetch(dashboard.dataset.orderUrl, {
                method: 'PATCH', credentials: 'same-origin', cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ widget_ids: ids() }),
            });
            if (!response.ok) throw new Error('Widget order rejected');
            showStatus('');
        } catch (_error) {
            applyIds(previous);
            showStatus('Не удалось сохранить порядок. Расположение восстановлено.', true);
        } finally {
            savingOrder = false;
            markEditing();
            refresh();
        }
    };
    const changeOrder = (source, target) => {
        if (savingOrder || savingVisibility || !movable(source) || !movable(target)) return;
        const previous = ids();
        const next = reorderDashboardWidgets(
            slots(), Number(source.dataset.dashboardWidget), Number(target.dataset.dashboardWidget)
        );
        if (!next) return;
        applyIds(next);
        void saveOrder(previous);
    };
    const finishDrag = () => {
        dragged = null;
        touch = null;
        dragging = false;
        widgets().forEach(node => {
            node.draggable = false;
            node.classList.remove('app-dashboard-widget--dragging', 'app-dashboard-widget--drag-target');
        });
        markEditing();
    };

    // Pointer capture is unnecessary for mouse HTML5 DnD. Touch/pen uses a
    // pointer fallback because mobile browsers do not reliably support HTML5
    // drag/drop. The destination is the widget beneath the released pointer.
    dashboard.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.isPrimary === false || savingOrder || savingVisibility) return;
        if (event.target.closest(interactive)) return;
        const header = event.target.closest('[data-dashboard-drag-handle]');
        const node = header?.closest('[data-dashboard-widget]');
        if (!movable(node)) return;
        if (event.pointerType === 'mouse') {
            node.draggable = true;
        } else {
            touch = { source: node, id: event.pointerId, x: event.clientX, y: event.clientY, active: false };
        }
    });

    window.addEventListener('pointermove', event => {
        if (!touch || event.pointerId !== touch.id) return;
        if (!touch.active) {
            if (Math.hypot(event.clientX - touch.x, event.clientY - touch.y) < 8) return;
            touch.active = true;
            dragged = touch.source;
            dragging = true;
            dragged.classList.add('app-dashboard-widget--dragging');
            markEditing();
        }
        event.preventDefault();
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-dashboard-widget]');
        highlightTarget(target);
    }, { passive: false });
    window.addEventListener('pointerup', event => {
        if (!touch || touch.id !== event.pointerId) return;
        if (touch.active) {
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-dashboard-widget]');
            changeOrder(touch.source, target);
        }
        finishDrag();
    });
    window.addEventListener('pointercancel', event => {
        if (touch?.id === event.pointerId) finishDrag();
    });

    dashboard.addEventListener('dragstart', event => {
        const node = event.target.closest('[data-dashboard-widget]');
        if (savingOrder || savingVisibility || !movable(node) || !node.draggable) {
            event.preventDefault();
            return;
        }
        dragged = node;
        dragging = true;
        markEditing();
        node.classList.add('app-dashboard-widget--dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', node.dataset.dashboardWidget);
    });
    dashboard.addEventListener('dragover', event => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!movable(target) || target === dragged) {
            highlightTarget(null);
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        highlightTarget(target);
    });
    dashboard.addEventListener('drop', event => {
        if (!dragged) return;
        const target = event.target.closest('[data-dashboard-widget]');
        if (!movable(target) || target === dragged) return;
        event.preventDefault();
        changeOrder(dragged, target);
    });
    dashboard.addEventListener('dragend', finishDrag);
    dashboard.addEventListener('pointerup', () => {
        if (!dragging) widgets().forEach(node => { node.draggable = false; });
    });

    // Keyboard access without permanently displayed reorder buttons.
    dashboard.addEventListener('keydown', event => {
        if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        const header = event.target.closest('[data-dashboard-drag-handle]');
        if (!header || event.target !== header) return;
        const source = header.closest('[data-dashboard-widget]');
        const ordered = widgets().filter(movable);
        const at = ordered.indexOf(source);
        const next = at + (event.key === 'ArrowUp' ? -1 : 1);
        if (next < 0 || next >= ordered.length) return;
        event.preventDefault();
        changeOrder(source, ordered[next]);
        source.querySelector('[data-dashboard-drag-handle]')?.focus();
    });

    document.addEventListener('mskba:dashboard-edit-cancel', () => {
        finishDrag();
        showStatus('Доступные виджеты обновились. Проверь новое расположение.');
    });

    const fetchCatalog = async () => {
        const response = await fetch(dashboard.dataset.refreshUrl, {
            credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Failed to fetch widget catalog');
        const data = await response.json();
        if (!Array.isArray(data.available)) throw new Error('Invalid widget catalog');
        return data.available;
    };
    const saveVisibility = async visibleIds => {
        const response = await fetch(dashboard.dataset.visibilityUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {
                'Content-Type': 'application/json', Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ visible_widget_ids: visibleIds }),
        });
        if (!response.ok) throw new Error('Widget visibility rejected');
    };

    const renderPicker = catalog => {
        if (!pickerList) return;
        pickerList.replaceChildren();
        const hidden = catalog.filter(item => !item.visible);
        if (!hidden.length) {
            const empty = document.createElement('p');
            empty.textContent = 'Все доступные виджеты уже добавлены.';
            pickerList.appendChild(empty);
            return;
        }
        hidden.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-dashboard-picker__option';
            button.dataset.dashboardPickerAdd = String(item.id);
            const title = document.createElement('span');
            title.textContent = item.title;
            const plus = document.createElement('span');
            plus.className = 'app-dashboard-picker__option-plus';
            plus.setAttribute('aria-hidden', 'true');
            plus.textContent = '+';
            button.append(title, plus);
            pickerList.appendChild(button);
        });
    };

    dashboard.addEventListener('click', async event => {
        const addTile = event.target.closest('[data-dashboard-add-widget]');
        if (addTile && picker && !savingVisibility) {
            pickerList.replaceChildren();
            if (pickerStatus) pickerStatus.textContent = 'Загружаем список виджетов…';
            picker.showModal();
            try {
                pickerCatalog = await fetchCatalog();
                if (!picker.open) return;
                renderPicker(pickerCatalog);
                if (pickerStatus) pickerStatus.textContent = '';
            } catch (_error) {
                if (pickerStatus) pickerStatus.textContent = 'Не удалось загрузить виджеты. Попробуй ещё раз.';
            }
            return;
        }
        const hide = event.target.closest('[data-dashboard-hide-widget]');
        if (!hide || savingVisibility || savingOrder) return;
        const id = Number(hide.dataset.dashboardHideWidget);
        savingVisibility = true;
        hide.disabled = true;
        showStatus('');
        try {
            await saveVisibility(ids().filter(value => value !== id));
            refresh();
        } catch (_error) {
            showStatus('Не удалось убрать виджет. Попробуй ещё раз.', true);
            refresh();
        } finally {
            savingVisibility = false;
            hide.disabled = false;
        }
    });

    picker?.addEventListener('click', async event => {
        if (event.target.closest('[data-dashboard-picker-close]')) {
            picker.close();
            return;
        }
        const button = event.target.closest('[data-dashboard-picker-add]');
        if (!button || savingVisibility || savingOrder) return;
        const id = Number(button.dataset.dashboardPickerAdd);
        savingVisibility = true;
        button.disabled = true;
        if (pickerStatus) pickerStatus.textContent = '';
        try {
            // Use the server's latest visibility snapshot, not stale cards.
            const catalog = await fetchCatalog();
            const selected = catalog.find(item => item.id === id && !item.visible);
            if (!selected) throw new Error('Widget is no longer available');
            await saveVisibility([...catalog.filter(item => item.visible).map(item => item.id), id]);
            picker.close();
            refresh();
        } catch (_error) {
            if (pickerStatus) pickerStatus.textContent = 'Не удалось добавить виджет. Попробуй ещё раз.';
            refresh();
        } finally {
            savingVisibility = false;
            button.disabled = false;
        }
    });
    markEditing();
}