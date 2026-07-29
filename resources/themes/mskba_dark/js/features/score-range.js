document.querySelectorAll('[data-score-range]').forEach((range) => {
    const input = range.querySelector('[data-score-range-input]');
    const value = range.querySelector('[data-score-range-value]');

    if (!input || !value) {
        return;
    }

    const render = () => {
        const min = Number(input.min);
        const max = Number(input.max);
        const current = Number(input.value);
        const progress = ((current - min) / (max - min)) * 100;

        range.style.setProperty('--score-progress', `${progress}%`);
        input.setAttribute('aria-valuenow', String(current));
        value.value = String(current);
        value.textContent = String(current);
    };

    input.addEventListener('input', render);
    render();
});
