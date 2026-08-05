document.querySelectorAll('[data-team-name-form]').forEach((form) => {
    const input = form.querySelector('[data-team-name-input]');
    const warning = form.querySelector('[data-team-name-warning]');
    const url = form.dataset.teamNameSuggestionUrl;
    let timer;
    let controller;

    if (!input || !warning || !url) return;

    const hideWarning = () => {
        warning.hidden = true;
        warning.textContent = '';
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        hideWarning();
        const name = input.value.trim();
        if (name.length < 2) return;

        timer = setTimeout(async () => {
            controller = new AbortController();
            const params = new URLSearchParams({ name });
            if (form.dataset.teamNameExcept) params.set('except', form.dataset.teamNameExcept);

            try {
                const response = await fetch(`${url}?${params}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) return;
                const result = await response.json();
                if (!result.has_duplicate) return;
                warning.textContent = result.owned_by_current_user
                    ? 'У вас уже есть активная команда с таким названием. Измените существующую команду или выберите другое название.'
                    : `Такое название уже используется. Команда будет создана как «${result.suggested_name}».`;
                warning.hidden = false;
            } catch (error) {
                if (error.name !== 'AbortError') hideWarning();
            }
        }, 300);
    });
});
