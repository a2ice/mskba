const VENUE_HEADING_LIMIT = 40;

document.addEventListener('DOMContentLoaded', () => {
    const heading = document.querySelector('.venue-section .section-sidebar-layout__title[title]');
    if (!heading) return;

    const fullTitle = String(heading.getAttribute('title') || '').trim();
    if (!fullTitle) return;

    const characters = Array.from(fullTitle);
    heading.textContent = characters.length > VENUE_HEADING_LIMIT
        ? `${characters.slice(0, VENUE_HEADING_LIMIT).join('')}…`
        : fullTitle;
});
