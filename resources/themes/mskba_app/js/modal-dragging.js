// Shared drag controller for native MSKBA dialogs and legacy modal shell.
// Translation is kept on the dialog surface; never move the backdrop or
// change focus, modal stacking, overflow locking or the existing lifecycle.
const HANDLE = '.mskba-modal__header, .modal__header';
const BLOCKED = 'button, a, input, textarea, select, label, summary, [role="button"], [contenteditable], [data-modal-no-drag]';
const PAD = 8;
const THRESHOLD = 5;

export function constrainModalMove(rect, dx, dy, viewport, padding = PAD) {
    const minX = viewport.left + padding;
    const minY = viewport.top + padding;
    // For a dialog wider than the viewport, pin its left edge rather than
    // allowing the entire title bar to disappear.
    const maxX = Math.max(minX, viewport.left + viewport.width - rect.width - padding);
    const maxY = Math.max(minY, viewport.top + viewport.height - rect.height - padding);
    const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));
    return {
        dx: clamp(rect.left + dx, minX, maxX) - rect.left,
        dy: clamp(rect.top + dy, minY, maxY) - rect.top,
    };
}

export function visualModalViewport(win) {
    const vv = win.visualViewport;
    return vv
        ? { left: vv.offsetLeft, top: vv.offsetTop, width: vv.width, height: vv.height }
        : { left: 0, top: 0, width: win.innerWidth, height: win.innerHeight };
}

export function initModalDragging(doc = document, win = window) {
    const offsets = new WeakMap();
    const observed = new WeakSet();
    const tracked = new Set();
    let attempt = null;

    const isNative = node => node?.matches?.('dialog.mskba-modal');
    const isLegacy = node => node?.matches?.('.modal__dialog');
    const surfaceFor = handle => {
        const native = handle.closest?.('dialog.mskba-modal');
        if (native) return native.open ? native : null;
        const surface = handle.closest?.('.modal__dialog');
        if (!surface) return null;
        const shell = surface.closest('.modal');
        return shell?.classList.contains('is-open') && !shell.classList.contains('is-minimized')
            && !shell.hidden ? surface : null;
    };
    const active = surface => isNative(surface)
        ? Boolean(surface.open && surface.isConnected)
        : Boolean(isLegacy(surface) && surface.isConnected &&
            surface.closest('.modal')?.classList.contains('is-open') &&
            !surface.closest('.modal')?.classList.contains('is-minimized') &&
            !surface.closest('.modal')?.hidden);
    const view = () => visualModalViewport(win);
    const getOffset = surface => offsets.get(surface) || { x: 0, y: 0 };
    const setOffset = (surface, x, y) => {
        offsets.set(surface, {x, y});
        surface.style.translate = x + 'px ' + y + 'px';
    };
    const clear = surface => {
        if (!surface) return;
        surface.style.translate = '';
        offsets.delete(surface);
        tracked.delete(surface);
    };
    const finish = (cancel = false) => {
        if (!attempt) return;
        const state = attempt;
        attempt = null;
        if (cancel && state.dragging && state.surface.isConnected) {
            setOffset(state.surface, state.startOffset.x, state.startOffset.y);
        }
        if (state.dragging) {
            try { state.handle.releasePointerCapture?.(state.pointerId); } catch { /* released */ }
        }
        doc.documentElement.removeAttribute('data-mskba-modal-dragging');
        state.surface.removeAttribute('data-mskba-dragging');
    };
    const clampSurface = surface => {
        if (!active(surface)) { clear(surface); return; }
        const delta = constrainModalMove(surface.getBoundingClientRect(), 0, 0, view());
        if (delta.dx || delta.dy) {
            const old = getOffset(surface);
            setOffset(surface, old.x + delta.dx, old.y + delta.dy);
        }
    };
    const onDown = e => {
        if (attempt || e.button !== 0 || e.isPrimary === false) return;
        const target = e.target;
        if (!target?.closest || target.closest(BLOCKED)) return;
        const handle = target.closest(HANDLE);
        if (!handle) return;
        const surface = surfaceFor(handle);
        if (!surface) return;
        attempt = {
            handle, surface, pointerId: e.pointerId, startX: e.clientX, startY: e.clientY,
            lastX: e.clientX, lastY: e.clientY, startOffset: getOffset(surface), dragging: false,
        };
        tracked.add(surface);
    };
    const onMove = e => {
        const state = attempt;
        if (!state || e.pointerId !== state.pointerId) return;
        if (!active(state.surface)) { finish(); return; }
        if (!state.dragging) {
            if (Math.hypot(e.clientX - state.startX, e.clientY - state.startY) < THRESHOLD) return;
            state.dragging = true;
            doc.documentElement.setAttribute('data-mskba-modal-dragging', 'true');
            state.surface.setAttribute('data-mskba-dragging', 'true');
            try { state.handle.setPointerCapture?.(state.pointerId); } catch { /* window listeners still work */ }
        }
        const delta = constrainModalMove(
            state.surface.getBoundingClientRect(),
            e.clientX - state.lastX, e.clientY - state.lastY, view(),
        );
        const old = getOffset(state.surface);
        setOffset(state.surface, old.x + delta.dx, old.y + delta.dy);
        state.lastX = e.clientX;
        state.lastY = e.clientY;
        e.preventDefault?.();
    };
    const onUp = e => { if (attempt?.pointerId === e.pointerId) finish(); };
    const onCancel = e => { if (attempt?.pointerId === e.pointerId) finish(true); };
    const onKey = e => {
        if (e.key === 'Escape' && attempt?.dragging) {
            e.preventDefault(); finish(true); return;
        }
        if (!e.altKey || !e.key.startsWith('Arrow')) return;
        const handle = e.target?.closest?.(HANDLE);
        if (!handle || e.target !== handle) return;
        const surface = surfaceFor(handle);
        if (!surface) return;
        const amount = e.shiftKey ? 32 : 10;
        const dx = e.key === 'ArrowLeft' ? -amount : e.key === 'ArrowRight' ? amount : 0;
        const dy = e.key === 'ArrowUp' ? -amount : e.key === 'ArrowDown' ? amount : 0;
        if (!dx && !dy) return;
        e.preventDefault();
        const delta = constrainModalMove(surface.getBoundingClientRect(), dx, dy, view());
        const old = getOffset(surface);
        setOffset(surface, old.x + delta.dx, old.y + delta.dy);
        tracked.add(surface);
    };
    const onClose = e => {
        if (isNative(e.target)) {
            if (attempt?.surface === e.target) finish();
            clear(e.target);
        }
    };
    const prepareHandle = handle => {
        if (handle.hasAttribute('data-mskba-drag-handle')) return;
        handle.setAttribute('data-mskba-drag-handle', '');
        if (!handle.hasAttribute('tabindex')) handle.tabIndex = 0;
        handle.setAttribute('aria-keyshortcuts',
            'Alt+ArrowUp Alt+ArrowDown Alt+ArrowLeft Alt+ArrowRight');
        handle.setAttribute('title', 'Переместить окно: перетащи заголовок или Alt + стрелки');
    };
    const prepare = node => {
        if (!node?.querySelectorAll) return;
        if (node.matches?.(HANDLE)) prepareHandle(node);
        node.querySelectorAll(HANDLE).forEach(prepareHandle);
        const roots = [...node.querySelectorAll('dialog.mskba-modal, .modal')];
        if (node.matches?.('dialog.mskba-modal, .modal')) roots.push(node);
        for (const root of roots) {
            if (observed.has(root)) continue;
            observed.add(root);
            const lifecycle = new win.MutationObserver(() => {
                const surface = root.matches('dialog') ? root : root.querySelector('.modal__dialog');
                if (surface && !active(surface)) {
                    if (attempt?.surface === surface) finish();
                    clear(surface);
                }
            });
            lifecycle.observe(root, {attributes:true, attributeFilter:root.matches('dialog')?['open']:['class','hidden']});
        }
    };
    prepare(doc.body);
    const observer = new win.MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) prepare(node);
        if (attempt && !attempt.surface.isConnected) finish();
    });
    observer.observe(doc.body, {childList:true, subtree:true});
    const onViewportChange = () => {
        if (attempt?.dragging) finish(true);
        for (const surface of tracked) clampSurface(surface);
    };
    doc.addEventListener('pointerdown', onDown);
    win.addEventListener('pointermove', onMove, {passive:false});
    win.addEventListener('pointerup', onUp);
    win.addEventListener('pointercancel', onCancel);
    win.addEventListener('blur', () => finish(true));
    doc.addEventListener('keydown', onKey, true);
    doc.addEventListener('close', onClose, true);
    win.addEventListener('resize', onViewportChange);
    win.visualViewport?.addEventListener('resize', onViewportChange);
    win.visualViewport?.addEventListener('scroll', onViewportChange);
    return { destroy() {
        finish();
        observer.disconnect();
        doc.removeEventListener('pointerdown', onDown);
        win.removeEventListener('pointermove', onMove);
        win.removeEventListener('pointerup', onUp);
        win.removeEventListener('pointercancel', onCancel);
        doc.removeEventListener('keydown', onKey, true);
        doc.removeEventListener('close', onClose, true);
        win.removeEventListener('resize', onViewportChange);
        win.visualViewport?.removeEventListener('resize', onViewportChange);
        win.visualViewport?.removeEventListener('scroll', onViewportChange);
        for (const surface of tracked) clear(surface);
        doc.documentElement.removeAttribute('data-mskba-modal-dragging');
    }};
}
