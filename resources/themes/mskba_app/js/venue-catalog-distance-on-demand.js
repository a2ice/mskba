import { getCurrentCoordinates } from '../../mskba_dark/js/features/current-location.js';

document.addEventListener('DOMContentLoaded', () => {
    const settings = document.querySelector('[data-venue-distance-prompt]');
    const options = [...document.querySelectorAll('[data-venue-distance-option]')];
    const picker = document.querySelector('[data-nearby-filter]');
    const status = document.querySelector('[data-venue-distance-error]');
    if (!settings || !options.length || !picker || !status) return;
    let busy = false;
    let option = null;

    function finish() {
        busy = false;
        option.removeAttribute('aria-disabled');
        option.removeAttribute('aria-busy');
        option = null;
    }

    async function apply(point) {
        try {
            const response = await fetch(settings.dataset.locationUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ action: 'locate', latitude: point.latitude, longitude: point.longitude }),
            });
            if (!response.ok || !(await response.json()).available) throw new Error('Location not saved');
            window.location.assign(option.href);
        } catch {
            status.textContent = 'Не удалось применить сортировку. Попробуйте еще раз.';
            status.hidden = false;
            finish();
        }
    }

    for (const link of options) link.addEventListener('click', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        option = link;
        option.setAttribute('aria-disabled', 'true');
        option.setAttribute('aria-busy', 'true');
        status.hidden = true;
        let timer;
        let point;
        try {
            point = await Promise.race([
                getCurrentCoordinates(),
                new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timeout')), 13000); }),
            ]);
            if (!point || !Number.isFinite(point.latitude) || !Number.isFinite(point.longitude)
                || Math.abs(point.latitude) > 90 || Math.abs(point.longitude) > 180) throw new Error('coordinates');
        } catch {
            // Release the mobile dock's focus trap, if present, before opening
            // the same native dialog already used by the nearby filter.
            document.querySelector('[data-app-mobile-primary-filter-panel]:not([hidden])')
                ?.querySelector('[data-app-mobile-primary-close]')?.click();
            picker.dispatchEvent(new CustomEvent('mskba:venue-distance-select-point', {
                detail: {
                    select: selected => void apply(selected),
                    cancel: () => { finish(); link.focus({ preventScroll: true }); },
                },
            }));
            return;
        } finally {
            clearTimeout(timer);
        }
        await apply(point);
    });
});
