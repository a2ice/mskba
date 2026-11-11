/* Venue infinite scrolling. Use the existing project loading spinner. */
(() => {
    const root = document.querySelector('[data-venue-infinite-catalog]');
    if (!root) return;
    const items = root.querySelector('[data-venue-items]');
    const sentinel = root.querySelector('[data-venue-sentinel]');
    const spinner = root.querySelector('[data-venue-loader]');
    const error = root.querySelector('[data-venue-load-error]');
    const retry = root.querySelector('[data-venue-retry]');
    const counter = root.querySelector('[data-venue-visible-count]');
    if (!items || !sentinel || !spinner || !error || !counter) return;

    let nextPage = Number(root.dataset.nextPage) || null;
    let busy = false;
    let failed = false;
    const ids = new Set([...items.querySelectorAll('[data-venue-id]')].map(el => el.dataset.venueId));

    async function load() {
        if (busy || failed || !nextPage || document.visibilityState === 'hidden') return;
        busy = true;
        spinner.hidden = false;
        error.hidden = true;
        root.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(location.href);
            url.searchParams.set('page', String(nextPage));
            url.searchParams.set('fragment', 'items');
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const result = await response.json();
            if (typeof result.html !== 'string' || typeof result.has_more !== 'boolean') {
                throw new Error('Invalid response');
            }
            const template = document.createElement('template');
            template.innerHTML = result.html;
            for (const child of [...template.content.children]) {
                if (child.matches('[data-venue-id]')) {
                    if (ids.has(child.dataset.venueId)) continue;
                    ids.add(child.dataset.venueId);
                }
                items.append(child);
            }
            counter.textContent = String(ids.size);
            nextPage = result.has_more && Number.isInteger(Number(result.next_page))
                ? Number(result.next_page) : null;
            if (!nextPage) observer.disconnect();
        } catch {
            failed = true;
            error.hidden = false;
        } finally {
            busy = false;
            spinner.hidden = true;
            root.removeAttribute('aria-busy');
            if (!failed && nextPage && sentinel.getBoundingClientRect().top < innerHeight + 300) {
                requestAnimationFrame(() => void load());
            }
        }
    }

    const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) void load();
    }, { rootMargin: '300px 0px' });
    if (nextPage) observer.observe(sentinel);
    retry?.addEventListener('click', () => { failed = false; void load(); });
})();
