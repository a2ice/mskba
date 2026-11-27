import { getCurrentCoordinates } from '../../mskba_dark/js/features/current-location.js';

document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-venue-distance-prompt]');
    const catalog = document.querySelector('#venues-catalog');
    const menu = catalog?.querySelector('.app-catalog-tools__sort');
    // Retain the former proactive suggestion for a future opt-in.
    if (!dialog || !menu || dialog.dataset.autoPrompt !== '1') return;

    const template = menu.querySelector('[data-venue-distance-template]');
    const option = () => menu.querySelector('[data-venue-distance-option]');
    const optionPrototype = (option() || template?.content.firstElementChild)?.cloneNode(true);
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
    let coordinateRequest = null;
    let permissionPromptAttempted = false;
    const permissionPromptKey = 'mskba:venue-distance:permission-requested';

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
        if (!option() && optionPrototype) {
            menu.querySelector('.app-catalog-tools__sort-menu')?.append(optionPrototype.cloneNode(true));
        }
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
            const summary = menu.querySelector('summary');
            summary?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            summary?.focus({ preventScroll: true });
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

    function requestCoordinates({ prompt = false } = {}) {
        if (coordinateRequest) return coordinateRequest;
        if (prompt) {
            // Do not repeatedly show a native permission prompt on pagination,
            // filter changes or reload after the user dismisses it.
            if (permissionPromptAttempted) return;
            permissionPromptAttempted = true;
            try {
                if (window.sessionStorage.getItem(permissionPromptKey)) return;
                window.sessionStorage.setItem(permissionPromptKey, '1');
            } catch { /* storage may be disabled; the page-local guard remains */ }
        }
        coordinateRequest = Promise.resolve()
            .then(getCurrentCoordinates)
            .then(locate)
            .catch(() => { /* location is optional */ })
            .finally(() => { coordinateRequest = null; });
        return coordinateRequest;
    }

    async function permissionChanged(permission) {
        if (permission.state === 'granted') {
            if (available) {
                showOffer();
                return;
            }
            await requestCoordinates();
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

    function withoutPermissionApi() {
        if (available) showOffer();
        else void requestCoordinates({ prompt: true });
    }

    // On the first catalogue visit, actually request browser permission when
    // it has not yet been decided. A denial must not block or reprompt.
    if (navigator.permissions?.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(async permission => {
            permission.addEventListener?.('change', () => void permissionChanged(permission));
            await permissionChanged(permission);
            if (permission.state === 'prompt' && !available) {
                void requestCoordinates({ prompt: true });
            }
        }).catch(withoutPermissionApi);
    } else {
        withoutPermissionApi();
    }
});
