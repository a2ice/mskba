import { getCurrentCoordinates } from '../../mskba_dark/js/features/current-location.js';

document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-venue-distance-prompt]');
    const catalog = document.querySelector('#venues-catalog');
    const menu = catalog?.querySelector('.app-catalog-tools__sort');
    if (!dialog || !menu) return;

    const template = menu.querySelector('[data-venue-distance-template]');
    const option = () => menu.querySelector('[data-venue-distance-option]');
    const accept = dialog.querySelector('[data-distance-accept]');
    const manual = dialog.querySelector('[data-distance-manual]');
    const status = dialog.querySelector('[data-distance-status]');
    const initiallyAvailable = dialog.dataset.locationAvailable === '1';
    const selected = dialog.dataset.distanceSelected === '1';
    let available = initiallyAvailable;
    let answered = dialog.dataset.promptAnswered === '1';
    let attempted = false;
    let pending = null;
    let deciding = false;

    async function request(action, payload = {}) {
        const response = await fetch(dialog.dataset.locationUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ action, ...payload }),
        });
        if (!response.ok) throw new Error('Location request failed');
        return response.json();
    }

    const closeMobileFilter = () => {
        const panel = document.querySelector('[data-app-mobile-primary-filter-panel]:not([hidden])');
        panel?.querySelector('[data-app-mobile-primary-close]')?.click();
    };

    function showOption() {
        if (!option() && template) template.after(template.content.cloneNode(true));
    }

    function showOffer() {
        if (!available || answered || selected || attempted || dialog.open || !dialog.showModal) return;
        // The mobile filter dock has its own focus trap. Release it first.
        closeMobileFilter();
        menu.open = false;
        attempted = true;
        dialog.showModal();
        manual.focus({ preventScroll: true });
    }

    function sortUrl() {
        const link = option();
        return link ? link.href : null;
    }

    async function locate(point) {
        if (pending) return pending;
        if (available) {
            showOption();
            showOffer();
            return;
        }
        pending = (async () => {
            try {
                const state = await request('locate', point);
                available = state.available === true;
                answered = state.answered === true;
                if (!available) return;
                showOption();
                // A shared/stale distance URL is name-sorted until a real origin exists.
                if (selected && !initiallyAvailable && sortUrl()) {
                    window.location.assign(sortUrl());
                    return;
                }
                showOffer();
            } catch {
                // Geolocation is optional; the ordinary filter and sorting remain usable.
            } finally {
                pending = null;
            }
        })();
        return pending;
    }

    async function choose(sort) {
        if (deciding) return;
        deciding = true;
        accept.disabled = manual.disabled = true;
        status.hidden = true;
        try {
            const state = await request('answer');
            answered = state.answered === true;
            if (!answered) throw new Error('Choice not saved');
        } catch {
            status.hidden = false;
            return;
        } finally {
            deciding = false;
            accept.disabled = manual.disabled = false;
        }
        dialog.close();
        if (sort && sortUrl()) {
            window.location.assign(sortUrl());
        } else {
            menu.open = true;
            menu.querySelector('summary')?.focus({ preventScroll: true });
        }
    }

    accept.addEventListener('click', () => void choose(true));
    manual.addEventListener('click', () => void choose(false));
    dialog.addEventListener('cancel', event => {
        // Escape is an accessible equivalent of choosing manually.
        event.preventDefault();
        void choose(false);
    });

    document.addEventListener('mskba:venue-geolocation', event => {
        void locate(event.detail);
    });

    async function permissionChanged(permission) {
        if (permission.state === 'granted') {
            if (available) {
                showOffer();
                return;
            }
            try { await locate(await getCurrentCoordinates()); } catch { /* optional */ }
        } else if (available && permission.state !== 'granted') {
            try {
                await request('forget');
                available = false;
                option()?.remove();
                if (dialog.open) dialog.close();
                if (selected) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('sort');
                    url.searchParams.delete('page');
                    window.location.assign(url.href);
                }
            } catch { /* do not interrupt the catalogue */ }
        }
    }

    // Never initiate an unsolicited permission prompt. Reuse an existing grant
    // or the actual successful browser location from the nearby filter.
    if (navigator.permissions?.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(permission => {
            permission.addEventListener?.('change', () => void permissionChanged(permission));
            void permissionChanged(permission);
        }).catch(showOffer);
    } else {
        showOffer();
    }
});
