// One delegated tap/click tooltip; also supports infinite-scroll cards and keyboard.
let active = null;
let tooltip = null;
let sequence = 0;

function hide() {
    if (!active) return;
    active.setAttribute('aria-expanded', 'false');
    active.removeAttribute('aria-describedby');
    tooltip.hidden = true;
    active = null;
}

function show(button) {
    if (active === button) { hide(); return; }
    hide();
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.className = 'venue-card-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.append(tooltip);
    }
    active = button;
    tooltip.id = 'venue-card-tooltip-' + (++sequence);
    tooltip.textContent = button.dataset.venueCardTooltip;
    button.setAttribute('aria-expanded', 'true');
    button.setAttribute('aria-describedby', tooltip.id);
    tooltip.hidden = false;
    const rect = button.getBoundingClientRect();
    const box = tooltip.getBoundingClientRect();
    const left = Math.max(8, Math.min(innerWidth - box.width - 8, rect.left + rect.width / 2 - box.width / 2));
    const top = rect.top - box.height - 7 >= 8 ? rect.top - box.height - 7 : rect.bottom + 7;
    tooltip.style.left = left + 'px';
    tooltip.style.top = Math.max(8, Math.min(innerHeight - box.height - 8, top)) + 'px';
}

document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-venue-card-tooltip]');
    if (button) show(button);
    else hide();
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && active) { hide(); }
});
document.addEventListener('focusin', event => {
    if (active && event.target !== active) hide();
});
window.addEventListener('resize', hide);
window.addEventListener('scroll', hide, { capture: true, passive: true });
