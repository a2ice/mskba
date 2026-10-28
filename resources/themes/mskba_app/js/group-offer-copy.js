/* Task 299: copying a public group offer never triggers booking or sends a request. */
document.addEventListener('click', async event => {
    const trigger = event.target.closest?.('[data-group-offer-copy]');
    if (!trigger) return;
    const card = trigger.closest('.app-group-offers__card');
    const code = card?.querySelector('[data-group-offer-code]')?.textContent?.trim();
    const result = card?.querySelector('[data-group-offer-copy-result]');
    if (!code || !result) return;

    try {
        if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
        await navigator.clipboard.writeText(code);
        result.textContent = 'Скопировано';
    } catch {
        result.textContent = 'Не удалось скопировать — выделите код вручную';
    }
});
