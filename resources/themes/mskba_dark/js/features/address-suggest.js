document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-address-suggest]').forEach(initAddressSuggest);
});

function initAddressSuggest(container) {
    const input = container.querySelector('[data-address-suggest-input]');
    const list = container.querySelector('[data-address-suggest-list]');
    const error = container.querySelector('[data-address-suggest-error]');
    const locationButton = container.querySelector('[data-address-current-location]');
    const clearButton = container.querySelector('[data-address-clear]');
    const form = container.closest('form');
    const metroSelect = form?.querySelector('[data-address-metro-select]');
    const metroSummary = form?.querySelector('[data-address-metro-summary]');

    if (!input || !list) {
        return;
    }

    let timer = null;
    let requestId = 0;

    setAddressControlState(clearButton, input.value.trim() === '' ? 'idle' : 'clear');

    metroSelect?.addEventListener('change', () => {
        if (metroSelect.dataset.addressApplyingMetro === '1') {
            return;
        }

        metroSelect.dataset.addressUserMetroChanged = '1';
    });

    input.addEventListener('input', () => {
        clearTimeout(timer);
        requestId += 1;
        clearStructuredFields(container);
        clearMetroSelection(metroSelect);
        resetMetroSummary(metroSummary);
        hideError(error);

        const query = input.value.trim();

        if (query === '') {
            hideList(list);
            setAddressControlState(clearButton, 'idle');
            return;
        }

        if (query.length < 3) {
            hideList(list);
            setAddressControlState(clearButton, 'clear');
            return;
        }

        const activeRequestId = requestId;
        setAddressControlState(clearButton, 'loading');
        timer = setTimeout(() => {
            fetchSuggestions(input, list, error, activeRequestId, () => requestId, clearButton);
        }, 350);
    });

    input.addEventListener('address-suggest:sync', () => {
        setAddressControlState(clearButton, input.value.trim() === '' ? 'idle' : 'clear');
    });

    clearButton?.addEventListener('click', () => {
        clearTimeout(timer);
        requestId += 1;
        input.value = '';
        input.classList.remove('is-loading');
        clearStructuredFields(container);
        clearMetroSelection(metroSelect);
        resetMetroSummary(metroSummary);
        hideList(list);
        hideError(error);
        setAddressControlState(clearButton, 'idle');
        input.focus();
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) {
            hideList(list);
        }
    });

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-address-suggestion-index]');

        if (!button) {
            return;
        }

        const suggestions = JSON.parse(list.dataset.addressSuggestions || '[]');
        const suggestion = suggestions[Number(button.dataset.addressSuggestionIndex)];

        if (!suggestion) {
            return;
        }

        applySuggestion(suggestion);
        hideList(list);
        hideError(error);
    });

    locationButton?.addEventListener('click', async () => {
        const initialText = locationButton.textContent;
        locationButton.disabled = true;
        locationButton.textContent = 'Определяем местоположение…';
        setAddressControlState(clearButton, 'loading');
        hideError(error);

        try {
            const coordinates = await getCurrentCoordinates();
            const suggestion = await reverseGeocode(
                locationButton.dataset.addressReverseUrl,
                coordinates.latitude,
                coordinates.longitude,
            );

            if (!suggestion) {
                showError(error, 'Не удалось определить адрес. Выберите его вручную.');
                return;
            }

            applySuggestion(suggestion);
            hideList(list);
            hideError(error);
        } catch (geolocationError) {
            showError(error, geolocationError.code === 'permission_denied'
                    ? 'Разрешите доступ к геопозиции или выберите адрес вручную.'
                    : 'Не удалось получить геопозицию. Попробуйте ещё раз.');
        } finally {
            locationButton.disabled = false;
            locationButton.textContent = initialText;
            setAddressControlState(clearButton, input.value.trim() === '' ? 'idle' : 'clear');
        }
    });

    function applySuggestion(suggestion) {
        input.value = suggestion.label || '';
        fillStructuredFields(container, suggestion);
        setField(container, '[data-address-selected]', '1');
        applyMetroSuggestion(metroSelect, suggestion.metro_station_ids || []);
        updateMetroSummary(metroSelect, metroSummary);
        setAddressControlState(clearButton, 'clear');
    }
}

function updateMetroSummary(select, summary) {
    if (!summary) {
        return;
    }

    const labels = Array.from(select?.selectedOptions || [])
        .map((option) => option.textContent.trim())
        .filter(Boolean);

    summary.textContent = labels.length ? labels.join(', ') : 'Метро рядом не найдено';
}

function resetMetroSummary(summary) {
    if (summary) {
        summary.textContent = 'Подставится после выбора адреса';
    }
}

async function reverseGeocode(url, latitude, longitude) {
    if (!url) {
        return null;
    }

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ latitude, longitude }),
    });

    if (!response.ok) {
        return null;
    }

    const data = await response.json();

    return data?.suggestion || null;
}

function getCurrentCoordinates() {
    const telegram = window.Telegram?.WebApp;
    const locationManager = telegram?.LocationManager;

    if (locationManager && telegram.isVersionAtLeast?.('8.0')) {
        if (locationManager.isInited) {
            return requestTelegramLocation(locationManager);
        }

        return new Promise((resolve, reject) => {
            locationManager.init(() => {
                if (!locationManager.isLocationAvailable) {
                    reject(locationError('unavailable'));
                    return;
                }

                requestTelegramLocation(locationManager).then(resolve, reject);
            });
        });
    }

    if (!navigator.geolocation) {
        return Promise.reject(locationError('unavailable'));
    }

    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            (position) => resolve({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
            }),
            (error) => reject(locationError(
                error.code === error.PERMISSION_DENIED ? 'permission_denied' : 'unavailable',
            )),
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 },
        );
    });
}

function requestTelegramLocation(locationManager) {
    return new Promise((resolve, reject) => {
        locationManager.getLocation((location) => {
            if (!location) {
                reject(locationError('permission_denied'));
                return;
            }

            resolve({ latitude: location.latitude, longitude: location.longitude });
        });
    });
}

function locationError(code) {
    const error = new Error(code);
    error.code = code;

    return error;
}

async function fetchSuggestions(input, list, error, requestId, currentRequestId, clearButton) {
    const url = input.dataset.addressSuggestUrl;

    if (!url) {
        setAddressControlState(clearButton, input.value.trim() === '' ? 'idle' : 'clear');
        return;
    }

    input.classList.add('is-loading');

    try {
        const response = await fetch(`${url}?query=${encodeURIComponent(input.value.trim())}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (requestId !== currentRequestId()) {
            return;
        }

        if (!response.ok) {
            showError(error, 'Не удалось получить подсказки.');
            hideList(list);
            return;
        }

        const data = await response.json();
        const suggestions = Array.isArray(data?.suggestions) ? data.suggestions : [];

        renderSuggestions(list, suggestions);

        if (suggestions.length === 0) {
            showError(error, 'Варианты не найдены.');
        } else {
            hideError(error);
        }
    } catch (e) {
        if (requestId === currentRequestId()) {
            showError(error, 'Не удалось получить подсказки.');
            hideList(list);
        }
    } finally {
        if (requestId === currentRequestId()) {
            input.classList.remove('is-loading');
            setAddressControlState(clearButton, input.value.trim() === '' ? 'idle' : 'clear');
        }
    }
}

function setAddressControlState(control, state) {
    if (!control) {
        return;
    }

    control.hidden = state === 'idle';
    control.disabled = state === 'loading';
    control.classList.toggle('is-loading', state === 'loading');
    control.setAttribute('aria-label', state === 'loading' ? 'Загрузка адресов' : 'Очистить адрес');
}

function renderSuggestions(list, suggestions) {
    list.dataset.addressSuggestions = JSON.stringify(suggestions);

    if (suggestions.length === 0) {
        hideList(list);
        return;
    }

    list.innerHTML = suggestions.map((suggestion, index) => {
        const metroLabels = Array.isArray(suggestion.metro_station_labels) && suggestion.metro_station_labels.length
            ? `<span class="address-suggest__metro">${escapeHtml(suggestion.metro_station_labels.join(', '))}</span>`
            : '';

        return `
            <button class="address-suggest__item" type="button" data-address-suggestion-index="${index}">
                <span class="address-suggest__label">${escapeHtml(suggestion.label || '')}</span>
                ${metroLabels}
            </button>
        `;
    }).join('');

    list.classList.remove('d-none');
}

function fillStructuredFields(container, suggestion) {
    setField(container, '[data-address-city]', suggestion.city);
    setField(container, '[data-address-street]', suggestion.street);
    setField(container, '[data-address-building]', suggestion.building);
    setField(container, '[data-address-postal-code]', suggestion.postal_code);
    setField(container, '[data-address-latitude]', suggestion.latitude);
    setField(container, '[data-address-longitude]', suggestion.longitude);
}

function clearStructuredFields(container) {
    setField(container, '[data-address-selected]', '');
    setField(container, '[data-address-city]', '');
    setField(container, '[data-address-street]', '');
    setField(container, '[data-address-building]', '');
    setField(container, '[data-address-postal-code]', '');
    setField(container, '[data-address-latitude]', '');
    setField(container, '[data-address-longitude]', '');
}

function setField(container, selector, value) {
    const field = container.querySelector(selector);

    if (field) {
        field.value = value ?? '';
    }
}

function applyMetroSuggestion(select, stationIds) {
    if (!select) {
        return;
    }

    const ids = stationIds.map((id) => String(id));
    select.dataset.addressApplyingMetro = '1';
    select.dataset.addressAutofilledMetro = '1';
    delete select.dataset.addressUserMetroChanged;

    const tom = select.tomselect;

    if (tom) {
        tom.clear(true);
        ids.forEach((id) => tom.addItem(id, true));
        tom.refreshItems();
    } else {
        Array.from(select.options).forEach((option) => {
            option.selected = ids.includes(option.value);
        });
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
    delete select.dataset.addressApplyingMetro;
}

function clearMetroSelection(select) {
    if (!select) {
        return;
    }

    const tom = select.tomselect;

    if (tom) {
        tom.clear();
    } else {
        Array.from(select.options).forEach((option) => {
            option.selected = false;
        });
    }

    delete select.dataset.addressAutofilledMetro;
    delete select.dataset.addressUserMetroChanged;
}

function showError(error, message) {
    if (!error) {
        return;
    }

    error.textContent = message;
    error.classList.remove('d-none');
}

function hideError(error) {
    if (!error) {
        return;
    }

    error.textContent = '';
    error.classList.add('d-none');
}

function hideList(list) {
    list.classList.add('d-none');
    list.innerHTML = '';
    list.dataset.addressSuggestions = '[]';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
