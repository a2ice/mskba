/* Toggle only the clicked category subtree; current-path state is rendered by PHP. */
document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-shop-category-toggle]');
    if (!button) return;
    const id = button.getAttribute('aria-controls');
    const container = id ? document.getElementById(id) : null;
    if (!container) return;
    container.hidden = !container.hidden;
    button.setAttribute('aria-expanded', String(!container.hidden));
});
