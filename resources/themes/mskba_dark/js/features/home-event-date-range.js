import $ from 'jquery';

const OPTIONS_URL = '/home/location-options';

function initHomeEventDateRange(flow) {
    if (!flow || flow.dataset.homeFlow !== 'event') {
        return null;
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const dateRow = panel?.querySelector('.home-flow-row');
    const dateInputs = dateRow ? [...dateRow.querySelectorAll('input[type="date"]')] : [];
    const startInput = dateInputs[0] || null;
    const endInput = dateInputs[1] || null;

    if (!panel || !dateRow || !startInput || !endInput) {
        return null;
    }

    let defaultsPromise = null;
    let defaults = null;

    async function loadDefaults() {
        if (defaults) {
            return defaults;
        }
        if (defaultsPromise) {
            return defaultsPromise;
        }

        defaultsPromise = fetch(OPTIONS_URL, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.default_date_from || !payload.default_date_to) {
                    throw new Error('date_defaults_unavailable');
                }
                defaults = {
                    from: String(payload.default_date_from),
                    to: String(payload.default_date_to),
                    timezone: String(payload.timezone || ''),
                };
                return defaults;
            })
            .finally(() => {
                defaultsPromise = null;
            });

        return defaultsPromise;
    }

    function syncConstraints() {
        if (startInput.value) {
            endInput.min = startInput.value;
            if (endInput.value && endInput.value < startInput.value) {
                endInput.value = startInput.value;
            }
        } else {
            endInput.removeAttribute('min');
        }
    }

    async function applyDefaults(force = false) {
        try {
            const values = await loadDefaults();
            if (force || !startInput.value) {
                startInput.value = values.from;
            }
            if (force || !endInput.value) {
                endInput.value = values.to;
            }
            syncConstraints();
        } catch (_) {
            // Search must remain usable even if the lightweight config request fails.
            syncConstraints();
        }
    }

    startInput.addEventListener('change', syncConstraints);
    endInput.addEventListener('change', () => {
        if (startInput.value && endInput.value && endInput.value < startInput.value) {
            endInput.value = startInput.value;
        }
    });

    flow.addEventListener('click', (event) => {
        if (event.target.closest('[data-home-flow-type]')) {
            window.setTimeout(() => applyDefaults(true), 0);
        }
    });

    $(document).on('modal:opened.homeEventDateRange', function (_event, modal) {
        if (modal.find('[data-home-flow="event"]').get(0) === flow) {
            window.setTimeout(() => applyDefaults(true), 0);
        }
    });

    $(document).on('click.homeEventDateRange', '[data-home-flow="event"] [data-home-flow-tab="search"]', function () {
        window.setTimeout(() => applyDefaults(true), 0);
    });

    applyDefaults(false);

    return { applyDefaults, syncConstraints };
}

const eventFlow = document.querySelector('[data-home-flow="event"]');
initHomeEventDateRange(eventFlow);
