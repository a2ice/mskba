function initTournamentCheckIn() {
    const root = document.querySelector('[data-tournament-check-in][data-username-url]');
    const input = root?.querySelector('[data-check-in-username]');
    const message = root?.querySelector('[data-check-in-username-message]');
    const submit = root?.querySelector('[data-check-in-submit]');
    if (!root || !input || !message || !submit) return;

    let timer = null;
    let controller = null;
    const setState = (available, text) => {
        input.classList.toggle('is-valid', available === true);
        input.classList.toggle('is-invalid', available === false);
        message.textContent = text;
        submit.disabled = available !== true;
    };
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        controller?.abort();
        const username = input.value.trim();
        if (username.length < 3) {
            setState(null, 'Введите не менее трёх символов.');
            return;
        }
        setState(null, 'Проверяем логин…');
        timer = window.setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch(`${root.dataset.usernameUrl}?username=${encodeURIComponent(username)}`, {headers: {'Accept': 'application/json'}, signal: controller.signal});
                const payload = await response.json();
                setState(response.ok && payload.available === true, payload.message || 'Не удалось проверить логин.');
            } catch (error) {
                if (error.name !== 'AbortError') setState(false, 'Не удалось проверить логин. Попробуйте ещё раз.');
            }
        }, 350);
    });
}

document.addEventListener('DOMContentLoaded', initTournamentCheckIn);
