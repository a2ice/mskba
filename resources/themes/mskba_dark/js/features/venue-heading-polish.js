const VENUE_HEADING_LIMIT = 40;

document.addEventListener('DOMContentLoaded', () => {
    const heading = document.querySelector('.venue-section .section-sidebar-layout__title');
    if (!heading) return;

    const fullTitle = String(
        heading.getAttribute('title')
        || heading.dataset.tooltipSource
        || heading.dataset.tooltip
        || '',
    ).trim();
    if (!fullTitle) return;

    const characters = Array.from(fullTitle);
    const isTruncated = characters.length > VENUE_HEADING_LIMIT;

    heading.textContent = isTruncated
        ? `${characters.slice(0, VENUE_HEADING_LIMIT).join('')}…`
        : fullTitle;

    if (isTruncated) return;

    heading.removeAttribute('title');
    heading.removeAttribute('data-tooltip');
    heading.removeAttribute('data-tooltip-source');
    heading.classList.remove('ui-tooltip-source', 'ui-tooltip-source--title');

    if (heading.getAttribute('tabindex') === '0') {
        heading.removeAttribute('tabindex');
    }
});
