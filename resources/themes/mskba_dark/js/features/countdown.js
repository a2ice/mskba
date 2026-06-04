document.querySelectorAll('[data-countdown]').forEach((node) => {
    let secondsLeft = Number(node.dataset.countdownSeconds || node.textContent || 0);

    if (!Number.isFinite(secondsLeft) || secondsLeft <= 0) {
        return;
    }

    node.textContent = String(Math.ceil(secondsLeft));

    const timer = window.setInterval(() => {
        secondsLeft -= 1;
        node.textContent = String(Math.max(Math.ceil(secondsLeft), 0));

        if (secondsLeft <= 0) {
            window.clearInterval(timer);
            node.dispatchEvent(new CustomEvent('countdown:finished', {
                bubbles: true,
            }));

            const container = node.closest('[data-countdown-hide-on-finished]');

            if (container) {
                container.hidden = true;
            }
        }
    }, 1000);
});
