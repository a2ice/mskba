const search = document.querySelector('[data-profile-thread-search]');
if (search) {
    const form = search.closest('form');
    const selected = form.querySelector('[data-profile-thread-selected]');
    const submit = form.querySelector('[data-profile-thread-submit]');
    const results = form.querySelector('[data-profile-thread-results]');
    let sequence = 0;
    let controller = null;
    search.addEventListener('input', async () => {
        selected.value = '';
        submit.disabled = true;
        results.replaceChildren();
        controller?.abort();
        const q = search.value.trim();
        if (q.length < 2) return;
        const run = ++sequence;
        controller = new AbortController();
        try {
            const url = new URL(search.dataset.url, window.location.origin);
            url.searchParams.set('q', q);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal,
            });
            if (!response.ok) return;
            const data = await response.json();
            if (sequence !== run) return;
            for (const candidate of data.candidates || []) {
                const button = document.createElement('button');
                button.type = 'button';
                button.setAttribute('role', 'option');
                button.textContent = candidate.label;
                button.addEventListener('click', () => {
                    selected.value = String(candidate.id);
                    search.value = candidate.label;
                    submit.disabled = false;
                    results.replaceChildren();
                });
                results.append(button);
            }
        } catch (error) {
            if (error.name !== 'AbortError') results.replaceChildren();
        }
    });
}
