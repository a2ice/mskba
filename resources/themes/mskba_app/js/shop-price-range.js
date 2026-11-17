/* Dual price range: the same native keyboard-accessible sliders as player height/weight. */
document.querySelectorAll('[data-shop-price-range]').forEach(root => {
    const from = root.querySelector('[data-shop-price-min]');
    const to = root.querySelector('[data-shop-price-max]');
    const minField = root.querySelector('[data-shop-price-min-value]');
    const maxField = root.querySelector('[data-shop-price-max-value]');
    const minLabel = root.querySelector('[data-shop-price-label="min"]');
    const maxLabel = root.querySelector('[data-shop-price-label="max"]');
    if (!from || !to || !minField || !maxField || !minLabel || !maxLabel) return;
    const floor = Math.max(0, Number(root.dataset.priceFloor) || 0);
    const ceiling = Math.max(floor + 1, Number(root.dataset.priceCeiling) || 1000);
    const rubles = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

    function sync(changed) {
        let low = Math.max(floor, Math.min(ceiling, Number(from.value) || floor));
        let high = Math.max(floor, Math.min(ceiling, Number(to.value) || floor));
        if (low > high) {
            if (changed === from) high = low;
            else low = high;
        }
        from.value = String(low);
        to.value = String(high);
        minField.value = low === floor ? '' : String(low);
        maxField.value = high === ceiling ? '' : String(high);
        minLabel.textContent = rubles.format(low) + ' ₽';
        maxLabel.textContent = rubles.format(high) + ' ₽';
        const span = ceiling - floor;
        root.style.setProperty('--shop-price-start', ((low - floor) / span * 100) + '%');
        root.style.setProperty('--shop-price-end', ((high - floor) / span * 100) + '%');
        from.setAttribute('aria-valuetext', rubles.format(low) + ' рублей');
        to.setAttribute('aria-valuetext', rubles.format(high) + ' рублей');
    }

    from.addEventListener('input', () => sync(from));
    to.addEventListener('input', () => sync(to));
    sync(null);
});
