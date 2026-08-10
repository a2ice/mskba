document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-entity-predictive-search]').forEach(initEntityPredictiveSearch);
});

function initEntityPredictiveSearch(root) {
    const input = root.querySelector('[data-entity-predictive-input]');
    const value = root.querySelector('[data-entity-predictive-value]');
    const results = root.querySelector('[data-entity-predictive-results]');
    const clear = root.querySelector('[data-entity-predictive-clear]');
    const message = root.querySelector('[data-entity-predictive-message]');
    const staticOptions = [...root.querySelectorAll('[data-entity-predictive-option]')];
    const searchUrl = root.dataset.searchUrl || '';
    const minimumLength = Number(root.dataset.minimumLength || (searchUrl ? 2 : 1));
    let timer;
    let controller;

    if (!(input instanceof HTMLInputElement) || !(value instanceof HTMLInputElement) || !(results instanceof HTMLElement)) return;

    const showMessage = (text, error = false) => {
        if (!(message instanceof HTMLElement)) return;
        message.textContent = text;
        message.classList.toggle('text-danger', error);
        message.classList.toggle('text-muted', !error);
    };
    const resetSelection = () => {
        value.value = '';
        if (clear instanceof HTMLElement) clear.hidden = input.value === '';
    };
    const select = (id, label) => {
        input.value = label;
        value.value = id;
        results.classList.add('d-none');
        if (clear instanceof HTMLElement) clear.hidden = false;
        showMessage(`Выбрано: ${label}`);
    };
    const bindOption = (option) => option.addEventListener('click', () => select(option.dataset.id || '', option.dataset.label || ''));
    const renderRemote = (candidates) => {
        const options = candidates.map((candidate) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'predictive-search__item';
            option.dataset.entityPredictiveOption = '';
            option.dataset.id = candidate.id;
            option.dataset.label = candidate.name;
            const label = document.createElement('span');
            label.className = 'predictive-search__label';
            label.textContent = candidate.name;
            const meta = document.createElement('span');
            meta.className = 'predictive-search__meta';
            meta.textContent = candidate.meta || '';
            option.append(label, meta);
            bindOption(option);
            return option;
        });
        results.replaceChildren(...options);
        results.classList.toggle('d-none', options.length === 0);
        showMessage(options.length ? 'Выберите вариант из списка.' : 'Подходящих вариантов не найдено.');
    };

    staticOptions.forEach(bindOption);
    input.addEventListener('input', () => {
        resetSelection();
        window.clearTimeout(timer);
        controller?.abort();
        const query = input.value.trim();
        if (query.length < minimumLength) {
            results.classList.add('d-none');
            showMessage(`Введите не менее ${minimumLength} символов.`);
            return;
        }
        if (!searchUrl) {
            let visible = 0;
            staticOptions.forEach((option) => {
                const matches = (option.dataset.label || '').toLocaleLowerCase('ru').includes(query.toLocaleLowerCase('ru'));
                option.hidden = !matches;
                if (matches) visible++;
            });
            results.classList.toggle('d-none', visible === 0);
            showMessage(visible ? 'Выберите вариант из списка.' : 'Подходящих вариантов не найдено.');
            return;
        }
        timer = window.setTimeout(async () => {
            controller = new AbortController();
            clear?.classList.add('is-loading');
            if (clear instanceof HTMLElement) clear.hidden = false;
            try {
                const url = new URL(searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error('Не удалось выполнить поиск.');
                renderRemote((await response.json()).candidates || []);
            } catch (error) {
                if (error.name !== 'AbortError') showMessage(error.message, true);
            } finally {
                clear?.classList.remove('is-loading');
            }
        }, 250);
    });
    clear?.addEventListener('click', () => {
        input.value = '';
        resetSelection();
        results.classList.add('d-none');
        if (clear instanceof HTMLElement) clear.hidden = true;
        showMessage(`Введите не менее ${minimumLength} символов.`);
        input.focus();
    });
    root.closest('form')?.addEventListener('submit', (event) => {
        if (value.value) return;
        event.preventDefault();
        showMessage('Сначала выберите вариант из списка.', true);
        input.focus();
    });
}
