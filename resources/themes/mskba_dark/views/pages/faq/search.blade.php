<style>
    .faq-search { position: relative; max-width: 900px; }
    .faq-search__label { font-weight: 700; }
    .faq-search__control { position: relative; }
    .faq-search__control > i { position: absolute; left: 14px; top: 50%; z-index: 1; color: var(--muted); transform: translateY(-50%); pointer-events: none; }
    .faq-search__control .form-control { padding-left: 42px; }
    .faq-search__hint { display: block; margin-top: 8px; color: var(--muted); }
    .faq-search__results { position: absolute; top: calc(100% - 4px); left: 0; right: 0; z-index: 30; max-height: min(480px, 65vh); overflow-y: auto; border: 1px solid var(--line-strong); border-radius: 12px; background: var(--surface); box-shadow: 0 18px 48px rgba(0, 0, 0, .28); }
    .faq-search__group + .faq-search__group { border-top: 1px solid var(--line); }
    .faq-search__tag { padding: 10px 14px 6px; color: var(--accent); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .faq-search__articles { display: grid; }
    .faq-search__article { display: grid; gap: 3px; padding: 10px 14px; color: var(--text); text-decoration: none; }
    .faq-search__article:hover, .faq-search__article:focus-visible { background: rgba(255, 255, 255, .06); color: var(--text); }
    .faq-search__article span { color: var(--muted); font-size: 13px; line-height: 1.4; }
    .faq-search__empty { padding: 14px; color: var(--muted); }
    .faq-tags { display: flex; flex-wrap: wrap; gap: 7px; }
    .faq-tags--article { margin: 18px 0 24px; }
    .faq-tag { display: inline-flex; padding: 5px 9px; border: 1px solid var(--line); border-radius: 999px; color: var(--muted); font-size: 12px; }
    .content-requirements { margin: 0 0 28px; padding: 18px 20px; border: 1px solid var(--line); border-left: 3px solid var(--accent); border-radius: 12px; background: rgba(236, 127, 18, .07); }
    .content-requirements > strong { display: block; margin-bottom: 10px; font-family: var(--font-display); font-size: 22px; }
    .content-requirements ul { margin-bottom: 0 !important; }
    .content-anchor { display: block; scroll-margin-top: 110px; }
</style>

<div class="faq-search mb-4" data-faq-search data-endpoint="{{ route('faq.search') }}">
    <label class="form-label faq-search__label" for="faqPredictiveSearch">Поиск по FAQ</label>
    <div class="faq-search__control">
        <i class="ti ti-search" aria-hidden="true"></i>
        <input
            id="faqPredictiveSearch"
            class="form-control"
            type="search"
            autocomplete="off"
            placeholder="Например: площадка, подтверждение контакта"
            data-faq-search-input
        >
    </div>
    <small class="form-text faq-search__hint">Вводите именно суть, а не сам вопрос. Например: «подтверждение аккаунта», «добавление площадки», «создание турнира».</small>
    <div class="faq-search__results" data-faq-search-results hidden></div>
</div>
<script>
    (() => {
        const root = document.currentScript?.previousElementSibling;
        const input = root?.querySelector('[data-faq-search-input]');
        const results = root?.querySelector('[data-faq-search-results]');
        const endpoint = root?.dataset.endpoint;
        if (!root || !input || !results || !endpoint) return;

        let timer = null;
        let controller = null;

        const clear = () => {
            results.replaceChildren();
            results.hidden = true;
        };

        const render = (groups, query) => {
            results.replaceChildren();
            if (!Array.isArray(groups) || groups.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'faq-search__empty';
                empty.textContent = `По тегам для «${query}» ничего не найдено.`;
                results.append(empty);
                results.hidden = false;
                return;
            }

            groups.forEach((group) => {
                const section = document.createElement('section');
                section.className = 'faq-search__group';
                const tag = document.createElement('div');
                tag.className = 'faq-search__tag';
                tag.textContent = group.tag;
                section.append(tag);

                const articles = document.createElement('div');
                articles.className = 'faq-search__articles';
                (group.articles || []).forEach((article) => {
                    const link = document.createElement('a');
                    link.className = 'faq-search__article';
                    link.href = article.url;
                    const title = document.createElement('strong');
                    title.textContent = article.title;
                    link.append(title);
                    if (article.description) {
                        const description = document.createElement('span');
                        description.textContent = article.description;
                        link.append(description);
                    }
                    articles.append(link);
                });
                section.append(articles);
                results.append(section);
            });
            results.hidden = false;
        };

        const search = async () => {
            const query = input.value.trim();
            if (query.length < 2) return clear();
            controller?.abort();
            controller = new AbortController();

            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('FAQ search failed');
                const payload = await response.json();
                render(payload.results || [], query);
            } catch (error) {
                if (error.name !== 'AbortError') clear();
            }
        };

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, 220);
        });
        input.addEventListener('focus', () => {
            if (results.childElementCount > 0) results.hidden = false;
        });
        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) results.hidden = true;
        });
    })();
</script>
