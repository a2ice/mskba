const root = document.querySelector('[data-favorites-account]');
if (root) {
    const results = root.querySelector('[data-favorites-results]');
    const typeSelect = root.querySelector('[data-favorites-type]');
    const stateSelect = root.querySelector('[data-favorites-state]');
    const count = root.querySelector('[data-favorites-count]');
    let type = 'all', state = 'active', loadSequence = 0;
    const node = (tag, value, className = '') => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (value !== undefined && value !== null) el.textContent = value;
        return el;
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const renderSelected = () => {
        typeSelect.value = type;
        stateSelect.value = state;
    };
    const load = async () => {
        const sequence = ++loadSequence;
        renderSelected();
        results.replaceChildren(node('p', 'Загружаем…', 'muted'));
        try {
            const url = new URL(root.dataset.listUrl, location.origin);
            url.searchParams.set('type', type); url.searchParams.set('state', state);
            const response = await fetch(url, { credentials:'same-origin', headers:{ Accept:'application/json' } });
            if (!response.ok) throw new Error('Не удалось загрузить избранное.');
            const data = await response.json();
            if (sequence !== loadSequence) return;
            results.replaceChildren();
            if (count) count.textContent = 'Показано элементов: ' + data.data.length;
            if (!data.data.length) {
                const empty=node('p', state === 'active' ? 'Пока ничего не добавлено в избранное.' : 'Корзина пуста.', 'app-favorites__empty account-collection__empty');
                results.append(empty);
            }
            for (const item of data.data) {
                const row = node('article', null, 'app-favorites__item account-collection__card');
                const head = node('div', null, 'account-collection__card-head');
                const info = node('div', null, 'app-favorites__details');
                const title = item.available && item.url
                    ? node('a', item.title, 'app-favorites__item-link')
                    : node('strong', item.title);
                if (item.available && item.url) {
                    const url = new URL(item.url, location.origin);
                    if (url.origin === location.origin) title.href = url.href;
                }
                const heading = node('h2');
                heading.append(title);
                info.append(node('p', item.type_label, 'account-collection__eyebrow'), heading);
                const badge = node('span', state === 'active' ? 'В избранном' : 'В корзине', 'account-collection__status');
                if (state === 'active') badge.classList.add('is-active');
                head.append(info, badge);
                const toggle = node('button', state === 'active' ? 'Убрать в корзину' : 'Восстановить', 'button secondary account-collection__action');
                toggle.type = 'button';
                toggle.addEventListener('click', async () => {
                    toggle.disabled = true;
                    try {
                        const response = await fetch(root.dataset.toggleUrl, {
                            method:'POST', credentials:'same-origin',
                            headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf() },
                            body:JSON.stringify({ type:item.type, id:item.entity_id }),
                        });
                        if (!response.ok) throw new Error('Не получилось изменить избранное.');
                        const change = await response.json();
                        window.dispatchEvent(new CustomEvent('mskba:favorites:changed',
                            { detail: { count: change.favorites_count } }));
                        await load();
                    } catch (e) {
                        toggle.disabled = false;
                        results.prepend(node('p', e.message, 'error'));
                    }
                });
                const actions = node('div', null, 'account-collection__actions');
                actions.append(toggle);
                row.append(head, actions);
                results.append(row);
            }
        } catch(e) {
            if (sequence !== loadSequence) return;
            if (count) count.textContent = '';
            results.replaceChildren(node('p', e.message, 'error'));
        }
    };
    typeSelect.addEventListener('change', () => {
        type = typeSelect.value; void load();
    });
    stateSelect.addEventListener('change', () => {
        state = stateSelect.value; void load();
    });
    root.querySelector('[data-favorites-reset]')?.addEventListener('click', event => {
        event.preventDefault();
        type = 'all'; state = 'active'; void load();
    });
    void load();
}
