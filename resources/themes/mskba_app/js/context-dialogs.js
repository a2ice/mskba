const actions = document.querySelector('[data-app-context-actions]');
const placeholder = document.querySelector('[data-context-placeholder-dialog]');
const help = document.querySelector('[data-context-help-dialog]');
let lastTrigger = null;
let helpContext = '';
let faq = null;
let faqSection = null;
let faqArticle = null;
let waitingForAuth = false;

function showDialog(dialog, trigger) {
    lastTrigger = trigger;
    if (actions?.open) actions.open = false;
    if (!dialog?.open) dialog?.showModal();
    dialog?.querySelector('h2[tabindex="-1"]')?.focus({ preventScroll: true });
}
function closeDialog(dialog, restore = true) {
    if (dialog?.open) dialog.close();
    if (restore && lastTrigger?.isConnected) lastTrigger.focus({ preventScroll: true });
}
function bindBackdrop(dialog) {
    dialog?.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeDialog(dialog);
    });
}

if (actions && placeholder) {
    actions.addEventListener('click', (event) => {
        const button = event.target.closest('[data-context-placeholder]');
        if (!button) return;
        placeholder.querySelector('[data-context-placeholder-title]').textContent = button.dataset.contextPlaceholder;
        showDialog(placeholder, button);
    });
    placeholder.querySelector('[data-context-placeholder-close]')?.addEventListener('click', () => closeDialog(placeholder));
    bindBackdrop(placeholder);
}

if (actions && help) {
    const articleList = help.querySelector('[data-help-articles]');
    const lookup = help.querySelector('[data-help-search]');
    const options = help.querySelector('[data-help-options]');
    const breadcrumbs = help.querySelector('[data-help-breadcrumbs]');
    const form = help.querySelector('[data-help-form]');
    const guest = help.querySelector('[data-help-guest]');
    const feedback = help.querySelector('[data-help-form-status]');
    let requestId = 0;

    function node(tag, text, className = '') {
        const el = document.createElement(tag);
        if (text != null) el.textContent = text;
        if (className) el.className = className;
        return el;
    }
    function linkButton(text, onClick) {
        const button = node('button', text, 'app-context-help__link');
        button.type = 'button';
        button.addEventListener('click', onClick);
        return button;
    }
    function updateAuth(authenticated, token) {
        if (token) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
        form.hidden = !authenticated;
        guest.hidden = authenticated;
    }
    function render() {
        if (!faq) return;
        const groups = faq.sections || [];
        options.replaceChildren();
        for (const group of groups) {
            const option = node('option');
            option.value = group.label;
            options.append(option);
        }
        lookup.value = groups.find(s => s.key === faqSection)?.label || '';
        breadcrumbs.replaceChildren();
        for (const crumb of faq.breadcrumbs || []) {
            const crumbNode = linkButton(crumb.label, () => load(crumb.section, crumb.article));
            if (crumb.article === faqArticle && crumb.section === faqSection) crumbNode.setAttribute('aria-current', 'page');
            breadcrumbs.append(crumbNode);
        }
        articleList.replaceChildren();
        if (faq.article) {
            articleList.append(node('h3', faq.article.title));
            if (faq.article.description) articleList.append(node('p', faq.article.description));
            const content = node('div', null, 'app-context-help__rendered');
            // This HTML comes from the existing server-side sanitized ContentBodyRenderer.
            content.innerHTML = faq.article.html;
            articleList.append(content);
            return;
        }
        if (!faqSection) {
            articleList.append(node('p', 'Выберите раздел, чтобы найти нужную информацию.'));
            for (const group of groups) {
                const button = linkButton(group.label, () => load(group.key));
                button.append(node('span', `${group.articles.length}`, 'app-context-help__count'));
                articleList.append(button);
            }
            return;
        }
        const section = groups.find(s => s.key === faqSection);
        if (!section?.articles?.length) {
            articleList.append(node('p', 'По данному разделу пока нет информации. Вы можете задать вопрос ниже.'));
            return;
        }
        for (const item of section.articles) {
            const button = linkButton(item.title, () => load(faqSection, item.alias));
            if (item.description) button.append(node('small', item.description));
            articleList.append(button);
        }
    }
    async function load(section = null, article = null, usePageContext = false) {
        const id = ++requestId;
        articleList.replaceChildren(node('p', 'Загружаем ответы…'));
        const url = new URL(help.dataset.helpUrl, location.origin);
        if (usePageContext && helpContext) url.searchParams.set('context', helpContext);
        if (section) url.searchParams.set('section', section);
        if (article) url.searchParams.set('article', article);
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('help fetch failed');
            const data = await response.json();
            if (id !== requestId) return;
            faq = data;
            faqSection = data.section;
            faqArticle = data.article?.alias || null;
            updateAuth(data.authenticated, data.csrf_token);
            render();
        } catch {
            if (id === requestId) articleList.replaceChildren(node('p', 'Не удалось загрузить FAQ. Попробуйте ещё раз.'));
        }
    }
    function chooseSection() {
        if (!faq) return;
        const value = lookup.value.trim().toLocaleLowerCase();
        if (!value) return load();
        const section = faq.sections.find(s => s.label.toLocaleLowerCase() === value || s.key === value);
        if (section) load(section.key);
    }
    lookup.addEventListener('change', chooseSection);
    lookup.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); chooseSection(); } });
    actions.querySelector('[data-context-help]')?.addEventListener('click', (event) => {
        helpContext = event.currentTarget.dataset.helpContext || '';
        waitingForAuth = false;
        showDialog(help, event.currentTarget);
        load(null, null, true);
    });
    help.querySelector('[data-context-help-close]')?.addEventListener('click', () => closeDialog(help));
    bindBackdrop(help);
    help.querySelector('[data-help-auth]')?.addEventListener('click', () => {
        waitingForAuth = true;
        closeDialog(help, false);
        window.dispatchEvent(new CustomEvent('mskba:auth:open', { detail: { mode: 'login', stayOnPage: true } }));
    });
    window.addEventListener('mskba:auth:success', () => {
        if (!waitingForAuth) return;
        waitingForAuth = false;
        showDialog(help, lastTrigger);
        help.querySelector('[data-help-ask]').open = true;
        load(faqSection, faqArticle);
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        feedback.textContent = 'Отправляем вопрос…';
        try {
            const data = new FormData(form);
            const response = await fetch(help.dataset.questionUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ topic: data.get('topic'), message: data.get('message'), source_path: location.pathname }),
            });
            if (response.status === 401) { updateAuth(false); throw new Error('Авторизуйтесь и повторите попытку.'); }
            if (response.status === 419) throw new Error('Сессия истекла. Обновите страницу.');
            if (response.status === 429) throw new Error('Слишком много вопросов. Попробуйте позже.');
            if (!response.ok) throw new Error('Не удалось сохранить вопрос. Проверьте поля и попробуйте ещё раз.');
            const result = await response.json();
            feedback.textContent = result.message;
            form.reset();
        } catch (error) {
            feedback.textContent = error.message || 'Не удалось отправить вопрос.';
        } finally { submit.disabled = false; }
    });
}
