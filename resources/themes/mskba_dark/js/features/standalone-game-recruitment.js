import { setupBalancedFormation } from './balanced-formation.js';
import { enhanceAcceptedTeamPreview } from './standalone-game-accepted-teams-preview.js';

document.addEventListener('DOMContentLoaded', () => {
    const gameRoot = document.querySelector('[data-game-control]');
    if (!(gameRoot instanceof HTMLElement)) return;
    const liveUrl = gameRoot.dataset.gameLiveUrl;
    if (!liveUrl) return;

    const url = new URL(liveUrl, window.location.origin);
    url.pathname = url.pathname.replace(/\/live\/?$/, '');
    const recruitmentBase = `${url.pathname}/recruitment`;
    const management = gameRoot.hasAttribute('data-game-lifecycle-url');
    const panelUrl = `${recruitmentBase}/panel?management=${management ? '1' : '0'}`;
    let panel = null;

    const loadPanel = async () => {
        try {
            const response = await fetch(panelUrl, { headers: { Accept: 'text/html' } });
            if (response.status === 204 || response.status === 404) return;
            if (!response.ok) return;
            const html = await response.text();
            const template = document.createElement('template');
            template.innerHTML = html.trim();
            const next = template.content.firstElementChild;
            if (!(next instanceof HTMLElement)) return;

            if (panel?.isConnected) panel.replaceWith(next);
            else {
                const scoreboard = gameRoot.querySelector('[data-game-scoreboard]');
                if (scoreboard) scoreboard.before(next);
                else gameRoot.querySelector('.game-control__header')?.after(next);
            }
            panel = next;
            bindPanel(panel);
            enhanceAcceptedTeamPreview(panel);
        } catch (_) {
            // Recruitment is supplemental to the public game page; a transient
            // panel request error must not break score/lifecycle controls.
        }
    };

    const errorMessage = (data) => data?.message
        ?? Object.values(data?.errors ?? {})?.flat()?.[0]
        ?? 'Не удалось выполнить действие.';

    const submitAjaxForm = async (form) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const submitters = [...form.querySelectorAll('button, input[type="submit"]')];
        submitters.forEach((button) => { button.disabled = true; });
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: new FormData(form),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(errorMessage(data));
            if (form.dataset.reloadPage === '1') {
                window.location.reload();
                return;
            }
            await loadPanel();
        } catch (error) {
            window.alert(error.message);
            submitters.forEach((button) => { button.disabled = false; });
        }
    };

    const bindCandidateSearch = (root) => {
        root.querySelectorAll('[data-game-candidate-search]').forEach((searchRoot) => {
            const query = searchRoot.querySelector('[data-game-candidate-query]');
            const hidden = searchRoot.querySelector('[data-game-candidate-id]');
            const results = searchRoot.querySelector('[data-game-candidate-results]');
            const submit = searchRoot.querySelector('[data-game-candidate-submit]');
            if (!(query instanceof HTMLInputElement)
                || !(hidden instanceof HTMLInputElement)
                || !(results instanceof HTMLElement)
                || !(submit instanceof HTMLButtonElement)) return;

            let requestId = 0;
            let timer = null;
            const clear = () => {
                hidden.value = '';
                submit.disabled = true;
                results.replaceChildren();
                results.hidden = true;
            };
            query.addEventListener('input', () => {
                clearTimeout(timer);
                clear();
                const value = query.value.trim();
                if (value.length < 2) return;
                timer = setTimeout(async () => {
                    const current = ++requestId;
                    try {
                        const response = await fetch(`${searchRoot.dataset.searchUrl}?q=${encodeURIComponent(value)}`, {
                            headers: { Accept: 'application/json' },
                        });
                        const data = await response.json();
                        if (current !== requestId || !response.ok) return;
                        results.replaceChildren(...(data.candidates ?? []).map((candidate) => {
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'entity-predictive-search__option';
                            const strong = document.createElement('strong');
                            strong.textContent = candidate.name;
                            const small = document.createElement('small');
                            small.textContent = candidate.meta ?? '';
                            button.append(strong, small);
                            button.addEventListener('click', () => {
                                hidden.value = String(candidate.id);
                                query.value = candidate.name;
                                submit.disabled = false;
                                results.hidden = true;
                            });
                            return button;
                        }));
                        results.hidden = results.childElementCount === 0;
                    } catch (_) {
                        results.hidden = true;
                    }
                }, 250);
            });
        });
    };

    const bindConfiguration = (root) => {
        root.querySelectorAll('[data-recruitment-timing-mode]').forEach((select) => {
            if (!(select instanceof HTMLSelectElement)) return;
            const form = select.closest('form');
            const periods = form?.querySelector('[data-recruitment-periods-count]');
            if (!(periods instanceof HTMLSelectElement)) return;
            const sync = () => { periods.disabled = select.value !== 'periods'; };
            select.addEventListener('change', sync);
            sync();
        });
    };

    const bindPanel = (root) => {
        root.querySelectorAll('[data-game-recruitment-ajax]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) return;
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitAjaxForm(form);
            });
        });
        bindCandidateSearch(root);
        bindConfiguration(root);
        root.querySelectorAll('[data-balanced-formation]').forEach(setupBalancedFormation);
    };

    loadPanel();
});