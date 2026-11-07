document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-venue-editor]');
    if (!root) return;

    root.querySelectorAll('[data-venue-confirm-delete]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Удалить фотографию площадки?')) event.preventDefault();
        });
    });

    const address = root.querySelector('[data-venue-editor-address]');
    const suggestions = root.querySelector('[data-venue-editor-suggestions]');
    if (address && suggestions) {
        let timer;
        let controller;
        const form = address.closest('form');
        const set = (name, value) => {
            const field = form?.querySelector('[name="location[' + name + ']"]');
            if (field) field.value = value ?? '';
        };
        const close = () => { suggestions.hidden = true; suggestions.replaceChildren(); };
        address.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            close();
            set('address_selected', '0');
            set('latitude', '');
            set('longitude', '');
            const query = address.value.trim();
            if (query.length < 3) return;
            timer = window.setTimeout(async () => {
                controller = new AbortController();
                try {
                    const response = await fetch(address.dataset.suggestUrl + '?query=' + encodeURIComponent(query), {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                        credentials: 'same-origin',
                    });
                    if (!response.ok) return;
                    const data = await response.json();
                    if (address.value.trim() !== query || !Array.isArray(data.suggestions)) return;
                    const items = data.suggestions.slice(0, 8);
                    close();
                    for (const item of items) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = item.label || '';
                        button.addEventListener('click', () => {
                            address.value = item.label || '';
                            for (const key of ['city_id', 'district_id', 'city', 'street', 'building', 'postal_code', 'latitude', 'longitude']) {
                                set(key, item[key]);
                            }
                            set('address_selected', '1');
                            const metros = form.querySelector('[name="location[metro_station_ids][]"]');
                            if (metros) {
                                const matches = (item.metro_station_ids || []).map(String);
                                [...metros.options].forEach((option) => { option.selected = matches.includes(option.value); });
                            }
                            close();
                        });
                        suggestions.append(button);
                    }
                    suggestions.hidden = items.length === 0;
                } catch (error) {
                    if (error.name !== 'AbortError') close();
                }
            }, 350);
        });
        document.addEventListener('click', (event) => {
            if (!suggestions.contains(event.target) && event.target !== address) close();
        });
    }

    const schedule = root.querySelector('[data-venue-schedule-form]');
    if (!schedule) return;

    const insert = (template, parent, replacements) => {
        if (!template || !parent) return;
        let html = template.innerHTML;
        for (const [key, value] of Object.entries(replacements)) html = html.replaceAll(key, String(value));
        const fragment = document.createElement('template');
        fragment.innerHTML = html.trim();
        const element = fragment.content.firstElementChild;
        if (element) parent.append(element);
        return element;
    };

    const indexFor = (container) => {
        let max = -1;
        container.querySelectorAll('[name]').forEach((field) => {
            const match = field.name.match(/\]\[(\d+)\]\[(?:starts_at|ends_at)\]$/);
            if (match) max = Math.max(max, Number(match[1]));
        });
        return max + 1;
    };
    schedule.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) return;

        if (target.hasAttribute('data-venue-schedule-remove')) {
            const range = target.closest('.app-venue-editor__time-range');
            range?.remove();
        }
        if (target.hasAttribute('data-venue-schedule-add')) {
            const day = target.dataset.venueScheduleAdd;
            const container = schedule.querySelector('[data-venue-schedule-intervals="' + day + '"]');
            if (!container || container.children.length >= 3) return;
            insert(schedule.querySelector('[data-venue-week-template]'), container,
                { '__DAY__': day, '__INDEX__': indexFor(container) });
            target.closest('[data-venue-schedule-day]')?.querySelector('[data-venue-no-hours]')?.remove();
        }
        if (target.hasAttribute('data-venue-exception-add')) {
            const container = schedule.querySelector('[data-venue-exceptions]');
            if (!container || container.children.length >= 100) return;
            const ids = [...container.querySelectorAll('input[name^="exceptions["]')].map((input) =>
                Number((input.name.match(/^exceptions\[(\d+)\]/) || [])[1] || -1));
            const index = Math.max(-1, ...ids) + 1;
            insert(schedule.querySelector('[data-venue-exception-template]'), container, { '__EX__': index });
        }
        if (target.hasAttribute('data-venue-exception-remove')) {
            target.closest('[data-venue-exception]')?.remove();
        }
        if (target.hasAttribute('data-venue-exception-add-time')) {
            const exception = target.closest('[data-venue-exception]');
            const hours = exception?.querySelector('[data-venue-exception-hours]');
            if (!hours || hours.children.length >= 3) return;
            const dateName = exception.querySelector('input[type=date]')?.name || '';
            const index = (dateName.match(/^exceptions\[(\d+)\]/) || [])[1];
            if (index === undefined) return;
            insert(schedule.querySelector('[data-venue-exception-time-template]'), hours,
                { '__EX__': index, '__INDEX__': indexFor(hours) });
        }
    });
    schedule.addEventListener('change', (event) => {
        if (!event.target.matches('[data-venue-exception-closed]')) return;
        const exception = event.target.closest('[data-venue-exception]');
        const closed = event.target.checked;
        exception?.querySelectorAll('[data-venue-exception-hours] input').forEach((input) => {
            input.disabled = closed;
        });
    });
    schedule.querySelectorAll('[data-venue-exception-closed]:checked').forEach((input) =>
        input.dispatchEvent(new Event('change', { bubbles: true })));
});
