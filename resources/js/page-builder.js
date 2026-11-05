import '../css/page-builder-today-event-map.css';
import './page-builder-today-event-map.js';
// Hero Slider component runtime remains shared; homepage ball JS belongs to Filament.
import './page-builder-hero-slider.js';
import '../themes/mskba_app/js/features/page-builder-native-lqip.js';

// No dynamic HTML injection: server returns only bounded, authorized DTO fields.
const roots = document.querySelectorAll('[data-mskba-builder-source]');

async function load(root) {
    if (root.dataset.loaded === '1') return;
    root.dataset.loaded = '1';
    try {
        const response = await fetch(root.dataset.sourceUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            throw new Error(response.status === 401 ? 'Войди в аккаунт, чтобы увидеть свои игры.' : 'Не удалось загрузить данные.');
        }
        const data = await response.json();
        if (!Array.isArray(data.items)) throw new Error('Неверный ответ сервера.');
        if (!data.items.length) {
            const empty = document.createElement('p');
            empty.className = 'mskba-builder-empty';
            empty.textContent = 'Пока ничего нет.';
            root.replaceChildren(empty);
            return;
        }
        const items = data.items.slice(0, 12).map(item => {
            const article = document.createElement('article');
            article.className = 'mskba-builder-item';
            const title = document.createElement('a');
            const target = typeof item.url === 'string' && /^\/(?!\/)[a-zA-Z0-9/_-]*$/.test(item.url)
                ? item.url : '#';
            title.href = target;
            title.textContent = String(item.title ?? '');
            const subtitle = document.createElement('small');
            subtitle.textContent = String(item.subtitle ?? '');
            article.append(title, subtitle);
            return article;
        });
        root.replaceChildren(...items);
    } catch (error) {
        const message = document.createElement('p');
        message.className = 'mskba-builder-empty';
        message.setAttribute('role', 'alert');
        message.textContent = error.message || 'Не удалось загрузить данные.';
        root.replaceChildren(message);
        // Retry only by reloading the page, no infinite fetch loops.
    }
}

if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            void load(entry.target);
        }
    }, { rootMargin: '180px' });
    roots.forEach(root => observer.observe(root));
} else {
    roots.forEach(root => { void load(root); });
}
