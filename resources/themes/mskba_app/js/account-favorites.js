const root = document.querySelector('[data-favorites-account]');
if (root) {
    const results = root.querySelector('[data-favorites-results]');
    let type = 'all', state = 'active', loading = false;
    const node = (tag, value, className = '') => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (value !== undefined && value !== null) el.textContent = value;
        return el;
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const renderSelected = () => {
        root.querySelectorAll('[data-favorites-type]').forEach(el =>
            el.setAttribute('aria-pressed', String(el.dataset.favoritesType === type)));
        root.querySelectorAll('[data-favorites-state]').forEach(el =>
            el.setAttribute('aria-pressed', String(el.dataset.favoritesState === state)));
    };
    const load = async () => {
        if (loading) return;
        loading = true;
        renderSelected();
        results.replaceChildren(node('p', 'Загружаем…', 'muted'));
        try {
            const url = new URL(root.dataset.listUrl, location.origin);
            url.searchParams.set('type', type); url.searchParams.set('state', state);
            const response = await fetch(url, { credentials:'same-origin', headers:{ Accept:'application/json' } });
            if (!response.ok) throw new Error('Не удалось загрузить избранное.');
            const data = await response.json();
            results.replaceChildren();
            if (!data.data.length) {
                results.append(node('p', state === 'active' ? 'Пока ничего не добавлено в избранное.' : 'Корзина пуста.', 'muted'));
            }
            for (const item of data.data) {
                const row = node('article', null, 'app-favorites__item');
                const info = node('div', null, 'app-favorites__details');
                const title = item.available && item.url
                    ? node('a', item.title, 'app-favorites__item-link')
                    : node('strong', item.title);
                if (item.available && item.url) {
                    const url = new URL(item.url, location.origin);
                    if (url.origin === location.origin) title.href = url.href;
                }
                info.append(title, node('span', item.type_label, 'caption'));
                const toggle = node('button', state === 'active' ? 'Убрать в корзину' : 'Восстановить', 'button secondary');
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
                        loading = false;
                        await load();
                    } catch (e) {
                        toggle.disabled = false;
                        results.prepend(node('p', e.message, 'error'));
                    }
                });
                row.append(info, toggle);
                results.append(row);
            }
        } catch(e) {
            results.replaceChildren(node('p', e.message, 'error'));
        } finally { loading = false; }
    };
    root.querySelectorAll('[data-favorites-type]').forEach(el => el.addEventListener('click', () => {
        type = el.dataset.favoritesType; void load();
    }));
    root.querySelectorAll('[data-favorites-state]').forEach(el => el.addEventListener('click', () => {
        state = el.dataset.favoritesState; void load();
    }));
    void load();
}
