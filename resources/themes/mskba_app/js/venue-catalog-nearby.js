import { getCurrentCoordinates } from '../../mskba_dark/js/features/current-location.js';
import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import '../../../css/venue-catalog-nearby.css';

const coordinates = (latitude, longitude) => {
    if ([latitude, longitude].some(v => v === null || v === undefined || String(v).trim() === '')) return null;
    const lat = Number(latitude), lon = Number(longitude);
    return Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 ? [lat, lon] : null;
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-nearby-filter]').forEach(root => {
        const dialog = root.querySelector('[data-nearby-dialog]');
        const find = selector => root.querySelector(selector) || dialog?.querySelector(selector);
        const toggle = find('[data-nearby-toggle]'), options = find('[data-nearby-options]');
        const lat = find('[data-nearby-lat]'), lon = find('[data-nearby-lon]');
        const radius = find('[data-nearby-radius]'), radiusValue = find('[data-nearby-radius-value]');
        const status = find('[data-nearby-status]');
        const addressPanel = find('[data-nearby-address-panel]'), mapPanel = find('[data-nearby-map-panel]');
        const addressInput = find('[data-address-suggest-input]'), clearAddress = find('[data-address-clear]');
        const canvas = find('[data-nearby-map]'), dialogStatus = find('[data-nearby-dialog-status]');
        const confirm = find('[data-nearby-confirm]');
        let point = toggle.checked ? coordinates(lat.value, lon.value) : null;
        let draft = null, mode = 'address', map = null, marker = null, loading = false, generation = 0;
        let externalSelection = null;

        const message = (node, text = '') => { node.textContent = text; node.hidden = !text; };
        const sync = () => {
            toggle.checked = !!point;
            options.hidden = !point;
            lat.disabled = lon.disabled = radius.disabled = !point;
            lat.value = point?.[0] ?? '';
            lon.value = point?.[1] ?? '';
            radiusValue.textContent = radius.value;
        };
        const updateDraft = value => { draft = value; confirm.disabled = !draft; };
        const destroyMap = () => {
            if (map) map.destroy();
            map = marker = null;
            loading = false;
            canvas.replaceChildren();
        };
        const mark = value => {
            if (!map || !value) return;
            if (marker) marker.geometry.setCoordinates(value);
            else {
                marker = new window.ymaps.Placemark(value, {}, {preset:'islands#orangeDotIcon'});
                map.geoObjects.add(marker);
            }
        };
        const initializeMap = async () => {
            if (map || loading || !dialog.open || mode !== 'map') return;
            if (!canvas.dataset.mapApiKey) { message(dialogStatus, 'Карта временно недоступна. Выберите адрес.'); return; }
            loading = true;
            const token = generation;
            message(dialogStatus, 'Загружаем карту…');
            try {
                await loadYandexMaps(canvas.dataset.mapApiKey);
                if (token !== generation || !dialog.open || mode !== 'map') return;
                map = new window.ymaps.Map(canvas, {center: draft || point || [55.7558, 37.6173], zoom: draft || point ? 15 : 10, controls:['zoomControl']});
                mark(draft);
                map.events.add('click', event => {
                    const selected = event.get('coords');
                    updateDraft(coordinates(selected?.[0], selected?.[1]));
                    mark(draft);
                    message(dialogStatus);
                });
                message(dialogStatus);
            } catch {
                if (token === generation) message(dialogStatus, 'Карта временно недоступна. Выберите адрес.');
            } finally { if (token === generation) loading = false; }
        };
        const setMode = next => {
            if (mode !== next) {
                ++generation;
                destroyMap();
                // A coordinate selected in the map is not an accepted address suggestion.
                if (next === 'address') {
                    clearAddress.click();
                    updateDraft(null);
                }
            }
            mode = next;
            addressPanel.hidden = mode !== 'address';
            mapPanel.hidden = mode !== 'map';
            dialog.querySelectorAll('[data-nearby-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.nearbyMode === mode)));
            message(dialogStatus);
            if (mode === 'map') void initializeMap();
        };
        const open = (reason = '') => {
            if (dialog.open) return;
            ++generation;
            updateDraft(null);
            // Clear the previous text and structured address; it was only a draft.
            clearAddress.click();
            dialog.showModal();
            setMode('address');
            message(dialogStatus, reason);
            find('[data-nearby-close]').focus();
        };
        // Reuse this exact address/map picker for distance sorting without
        // changing the nearby filter, radius, or draft form values.
        root.addEventListener('mskba:venue-distance-select-point', event => {
            if (!event.detail?.select) return;
            if (dialog.open) { event.detail.cancel?.(); return; }
            externalSelection = event.detail;
            // The filter dock/sidebar can be display:none on mobile. Move the
            // actual dialog out of that hidden subtree, not a second copy.
            document.body.append(dialog);
            open('Не удалось получить местоположение. Выберите адрес или точку на карте.');
        });
        toggle.addEventListener('change', async () => {
            if (!toggle.checked) { point = null; sync(); message(status); return; }
            sync();
            toggle.disabled = true;
            message(status, 'Определяем местоположение…');
            let timer;
            try {
                const result = await Promise.race([
                    getCurrentCoordinates(),
                    new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timeout')), 13000); })
                ]);
                const selected = coordinates(result.latitude, result.longitude);
                if (!selected) throw new Error('coordinates');
                point = selected;
                sync();
                document.dispatchEvent(new CustomEvent('mskba:venue-geolocation', { detail: { latitude: selected[0], longitude: selected[1] } }));
            } catch {
                open('Не удалось получить местоположение. Выберите адрес или точку на карте.');
            } finally { clearTimeout(timer); toggle.disabled = false; message(status); }
        });
        radius.addEventListener('input', () => { radiusValue.textContent = radius.value; });
        find('[data-nearby-change]').addEventListener('click', () => open());
        find('[data-nearby-close]').addEventListener('click', () => dialog.close());
        dialog.querySelectorAll('[data-nearby-mode]').forEach(button => button.addEventListener('click', () => setMode(button.dataset.nearbyMode)));
        addressPanel.addEventListener('address-suggest:selected', () => {
            if (mode !== 'address') return;
            updateDraft(coordinates(find('[data-address-latitude]').value, find('[data-address-longitude]').value));
        });
        addressInput.addEventListener('input', () => updateDraft(null));
        clearAddress.addEventListener('click', () => updateDraft(null));
        addressInput.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        confirm.addEventListener('click', () => {
            if (!draft) return;
            if (externalSelection) {
                const selection = externalSelection;
                const selected = draft;
                externalSelection = null;
                dialog.close();
                selection.select({ latitude: selected[0], longitude: selected[1] });
                return;
            }
            point = draft;
            sync();
            dialog.close();
        });
        let pressedOutside = false;
        dialog.addEventListener('pointerdown', event => { pressedOutside = event.target === dialog; });
        dialog.addEventListener('pointerup', event => {
            if (pressedOutside && event.target === dialog) dialog.close();
            pressedOutside = false;
        });
        dialog.addEventListener('pointercancel', () => { pressedOutside = false; });
        dialog.addEventListener('close', () => {
            const selection = externalSelection;
            externalSelection = null;
            ++generation;
            destroyMap();
            updateDraft(null);
            if (dialog.parentNode !== root) root.append(dialog);
            sync();
            selection?.cancel?.();
        });
        window.addEventListener('pageshow', sync);
        sync();
    });
});
