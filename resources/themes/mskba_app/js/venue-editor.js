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
                                metros.dispatchEvent(new Event('change', { bubbles: true }));
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


// Shared account venue metro multi-select: searchable list + removable UI Kit chips.
// The underlying native multiple select remains the single submitted field.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-venue-metro-picker]').forEach((picker) => {
        const select = picker.querySelector('[data-venue-metro-select]');
        const search = picker.querySelector('[data-venue-metro-search]');
        const list = picker.querySelector('[data-venue-metro-options]');
        const chips = picker.querySelector('[data-venue-metro-chips]');
        if (!select || !search || !list || !chips) return;

        const stations = Array.from(select.options);
        let highlighted = -1;
        const closeList = () => {
            list.hidden = true;
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            highlighted = -1;
        };
        const renderChips = () => {
            chips.replaceChildren();
            stations.filter((station) => station.selected).forEach((station) => {
                const chip = document.createElement('span');
                chip.className = 'app-venue-metro__chip';
                const dot = document.createElement('span');
                dot.className = 'app-venue-metro__dot';
                dot.style.setProperty('--metro-dot', /^#[0-9a-fA-F]{6}$/.test(station.dataset.lineColor || '') ? station.dataset.lineColor : '#64748b');
                dot.setAttribute('aria-hidden', 'true');
                const text = document.createElement('span');
                text.textContent = station.textContent.trim();
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'app-venue-metro__remove';
                remove.textContent = '×';
                remove.disabled = select.disabled;
                remove.setAttribute('aria-label', 'Убрать станцию ' + text.textContent);
                remove.addEventListener('click', () => {
                    station.selected = false;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    search.focus();
                });
                chip.append(dot, text, remove);
                chips.append(chip);
            });
            chips.hidden = !chips.childElementCount;
        };
        const renderList = () => {
            list.replaceChildren();
            highlighted = -1;
            if (select.disabled) return closeList();
            const query = search.value.trim().toLocaleLowerCase('ru');
            const available = stations.filter((station) => !station.selected &&
                (station.textContent.toLocaleLowerCase('ru').includes(query)
                    || (station.dataset.lineName || '').toLocaleLowerCase('ru').includes(query))).slice(0, 12);
            if (!available.length) return closeList();

            available.forEach((station, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.role = 'option';
                button.id = 'venue-metro-option-' + index;
                button.setAttribute('aria-selected', 'false');
                button.textContent = station.textContent.trim();
                button.addEventListener('pointerdown', (event) => event.preventDefault());
                button.addEventListener('click', () => {
                    station.selected = true;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    search.value = '';
                    renderList();
                    search.focus();
                });
                list.append(button);
            });
            list.hidden = false;
            search.setAttribute('aria-expanded', 'true');
        };
        search.addEventListener('focus', renderList);
        search.addEventListener('input', renderList);
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeList();
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (list.hidden) renderList();
                const buttons = [...list.querySelectorAll('button')];
                if (!buttons.length) return;
                event.preventDefault();
                highlighted = (highlighted + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
                buttons.forEach((button, index) => {
                    button.classList.toggle('is-highlighted', highlighted === index);
                    button.setAttribute('aria-selected', highlighted === index ? 'true' : 'false');
                });
                search.setAttribute('aria-activedescendant', buttons[highlighted].id);
            }
            if (event.key === 'Enter' && !list.hidden) {
                const button = [...list.querySelectorAll('button')][highlighted < 0 ? 0 : highlighted];
                if (button) {
                    event.preventDefault();
                    button.click();
                }
            }
        });
        document.addEventListener('pointerdown', (event) => {
            if (!picker.contains(event.target)) closeList();
        });
        select.addEventListener('change', () => {
            renderChips();
            if (!list.hidden) renderList();
        });
        renderChips();
        closeList();
    });
});
