<style>
.venue-photo-crop { display: grid; gap: 1rem; }
.venue-photo-crop__hint { margin: 0; font-size: .875rem; line-height: 1.5; color: #737373; }
.venue-photo-crop__viewport { display: flex; justify-content: center; overflow: hidden; border-radius: .75rem; background: #151515; padding: .5rem; }
.venue-photo-crop__stage { position: relative; display: inline-block; max-width: 100%; touch-action: none; user-select: none; -webkit-user-select: none; cursor: crosshair; overflow: hidden; }
.venue-photo-crop__stage img { display: block; width: auto; height: auto; max-width: 100%; max-height: min(58dvh, 600px); pointer-events: none; }
.venue-photo-crop__frame { position: absolute; border: 2px solid #fff; box-shadow: 0 0 0 200vmax rgba(0,0,0,.57); touch-action: none; cursor: move; }
.venue-photo-crop__thirds { pointer-events: none; position: absolute; inset: 0; background: linear-gradient(to right, transparent 33.15%,rgba(255,255,255,.5) 33.15%,rgba(255,255,255,.5) 33.45%,transparent 33.45%,transparent 66.5%,rgba(255,255,255,.5) 66.5%,rgba(255,255,255,.5) 66.8%,transparent 66.8%),linear-gradient(to bottom,transparent 33.15%,rgba(255,255,255,.5) 33.15%,rgba(255,255,255,.5) 33.45%,transparent 33.45%,transparent 66.5%,rgba(255,255,255,.5) 66.5%,rgba(255,255,255,.5) 66.8%,transparent 66.8%); }
.venue-photo-crop__handle { position: absolute; width: 44px; height: 44px; border: 0; padding: 0; background: transparent; touch-action: none; -webkit-tap-highlight-color: transparent; }
.venue-photo-crop__handle::after { content: ''; position: absolute; inset: 17px; background: #f97316; border: 1px solid #fff; border-radius: 3px; box-shadow: 0 1px 3px #000a; }
.venue-photo-crop__handle--n { left: 50%; top: 0; transform: translate(-50%,-50%); cursor: ns-resize; }
.venue-photo-crop__handle--e { left: 100%; top: 50%; transform: translate(-50%,-50%); cursor: ew-resize; }
.venue-photo-crop__handle--s { left: 50%; top: 100%; transform: translate(-50%,-50%); cursor: ns-resize; }
.venue-photo-crop__handle--w { left: 0; top: 50%; transform: translate(-50%,-50%); cursor: ew-resize; }
.venue-photo-crop__handle--nw { left: 0; top: 0; transform: translate(-50%,-50%); cursor: nwse-resize; }
.venue-photo-crop__handle--ne { left: 100%; top: 0; transform: translate(-50%,-50%); cursor: nesw-resize; }
.venue-photo-crop__handle--se { left: 100%; top: 100%; transform: translate(-50%,-50%); cursor: nwse-resize; }
.venue-photo-crop__handle--sw { left: 0; top: 100%; transform: translate(-50%,-50%); cursor: nesw-resize; }
.venue-photo-crop__toolbar, .venue-photo-crop__footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.venue-photo-crop__controls { display: flex; align-items: center; gap: .5rem; font-size: .875rem; }
.venue-photo-crop__controls button { display: grid; place-items: center; font-size: 1.3rem; font-weight: bold; width: 44px; height: 44px; border-radius: .5rem; border: 1px solid #a3a3a3; }
.venue-photo-crop__controls button:disabled, .venue-photo-crop__reset:disabled, .venue-photo-crop__apply:disabled { opacity: .55; cursor: wait; }
.venue-photo-crop__reset { min-height: 44px; font-size: .875rem; padding: .6rem .75rem; border: 1px solid #a3a3a3; border-radius: .5rem; }
.venue-photo-crop__apply { border: 0; min-height: 44px; border-radius: .5rem; padding: .65rem 1rem; color: #fff; font-weight: 600; background: #ea580c; }
.venue-photo-crop__status { margin: 0; font-size: .875rem; }
@media (max-width: 640px) {
    .venue-photo-crop__viewport { margin-inline: -.75rem; border-radius: 0; padding: .4rem; }
    .venue-photo-crop__footer { align-items: stretch; flex-direction: column; }
    .venue-photo-crop__apply { width: 100%; }
}
</style>
<script>
(function () {
    'use strict';

    const MIN = 0.02;
    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
    const copy = (rect) => ({x: rect.x, y: rect.y, width: rect.width, height: rect.height});
    const normalized = (rect) => Object.fromEntries(
        Object.entries(rect).map(([key, value]) => [key, Number(value.toFixed(6))])
    );

    window.venuePhotoCropEditor = (photoId, initial) => ({
        photoId,
        rect: {
            x: Number(initial.x), y: Number(initial.y),
            width: Number(initial.width), height: Number(initial.height),
        },
        pointers: new Map(),
        dragging: null,
        pinch: null,
        pending: Promise.resolve(),
        lastSaved: '',
        busy: false,
        status: 'Выберите нужную область фотографии.',

        get frameStyle() {
            const r = this.rect;
            return 'left:' + (r.x * 100) + '%;top:' + (r.y * 100) +
                '%;width:' + (r.width * 100) + '%;height:' + (r.height * 100) + '%;';
        },

        start(event) {
            if (this.busy || (event.pointerType === 'mouse' && event.button !== 0)) return;
            this.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
            this.$refs.stage.setPointerCapture(event.pointerId);

            if (this.pointers.size === 2) {
                const points = [...this.pointers.values()];
                const distance = Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
                this.pinch = {distance: Math.max(1, distance), rect: copy(this.rect)};
                this.dragging = null;
            } else if (this.pointers.size === 1) {
                const handle = event.target.closest('[data-crop-handle]');
                const direction = handle?.dataset.cropHandle ??
                    (event.target.closest('[data-crop-frame]') ? 'move' : null);
                const bounds = this.$refs.stage.getBoundingClientRect();
                this.dragging = direction ? {
                    direction, x: event.clientX, y: event.clientY,
                    width: bounds.width, height: bounds.height, rect: copy(this.rect),
                } : null;
            }

            event.preventDefault();
        },

        move(event) {
            if (!this.pointers.has(event.pointerId) || this.busy) return;
            this.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});

            if (this.pinch && this.pointers.size >= 2) {
                const points = [...this.pointers.values()];
                const distance = Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
                this.scaleFrame(this.pinch.rect, this.pinch.distance / Math.max(1, distance));
                return;
            }

            if (!this.dragging) return;

            const drag = this.dragging;
            const dx = (event.clientX - drag.x) / Math.max(1, drag.width);
            const dy = (event.clientY - drag.y) / Math.max(1, drag.height);
            const before = drag.rect;

            if (drag.direction === 'move') {
                this.rect = {
                    ...before,
                    x: clamp(before.x + dx, 0, 1 - before.width),
                    y: clamp(before.y + dy, 0, 1 - before.height),
                };
            } else {
                let left = before.x;
                let top = before.y;
                let right = before.x + before.width;
                let bottom = before.y + before.height;

                if (drag.direction.includes('w')) left = clamp(before.x + dx, 0, right - MIN);
                if (drag.direction.includes('e')) right = clamp(before.x + before.width + dx, left + MIN, 1);
                if (drag.direction.includes('n')) top = clamp(before.y + dy, 0, bottom - MIN);
                if (drag.direction.includes('s')) bottom = clamp(before.y + before.height + dy, top + MIN, 1);

                this.rect = {x: left, y: top, width: right - left, height: bottom - top};
            }

            this.status = 'Изменения кадрирования…';
        },

        end(event) {
            if (!this.pointers.has(event.pointerId)) return;
            this.pointers.delete(event.pointerId);
            if (this.pointers.size < 2) this.pinch = null;
            this.dragging = null;
            this.persist().catch(() => {});
        },

        scaleFrame(base, factor) {
            const width = clamp(base.width * factor, MIN, 1);
            const height = clamp(base.height * factor, MIN, 1);
            this.rect = {
                x: clamp(base.x + base.width / 2 - width / 2, 0, 1 - width),
                y: clamp(base.y + base.height / 2 - height / 2, 0, 1 - height),
                width, height,
            };
            this.status = 'Изменения кадрирования…';
        },

        zoom(factor) {
            if (this.busy) return;
            this.scaleFrame(copy(this.rect), factor);
            this.persist().catch(() => {});
        },

        reset() {
            if (this.busy) return;
            this.rect = {x: 0, y: 0, width: 1, height: 1};
            this.persist().catch(() => {});
        },

        persist() {
            const snapshot = normalized(copy(this.rect));
            const signature = JSON.stringify(snapshot);
            if (signature === this.lastSaved) return this.pending;
            this.status = 'Сохраняем черновик…';

            this.pending = this.pending.catch(() => {}).then(() =>
                this.$wire.saveVenuePhotoCropDraft(this.photoId, snapshot)
            ).then(() => {
                this.lastSaved = signature;
                if (!this.busy) this.status = 'Черновик сохранён.';
            }).catch((error) => {
                this.status = 'Не удалось сохранить черновик. Попробуйте ещё раз.';
                throw error;
            });

            return this.pending;
        },

        async apply() {
            if (this.busy) return;
            this.busy = true;

            try {
                await this.persist();
                await this.$wire.applyVenuePhotoCrop(this.photoId);
                this.status = 'Кадрирование применено. Можно закрыть редактор.';
            } catch (error) {
                this.status = 'Не удалось применить кадрирование. Повторите попытку.';
            } finally {
                this.busy = false;
            }
        },
    });
})();
</script>
