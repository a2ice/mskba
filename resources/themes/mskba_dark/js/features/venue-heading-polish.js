const VENUE_HEADING_LIMIT = 40;

document.addEventListener('DOMContentLoaded', () => {
    polishVenueHeading();
    polishVenueRating();
});

function polishVenueHeading() {
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
}

function polishVenueRating() {
    const rating = document.querySelector('.venue-section .venue-star-rating');
    if (!rating) return;

    const base = rating.querySelector('.venue-star-rating__base');
    const fill = rating.querySelector('.venue-star-rating__fill');
    const compact = rating.querySelector('.venue-star-rating__compact');

    if (base && fill && !rating.querySelector('.venue-star-rating__stars')) {
        const stars = document.createElement('span');
        stars.className = 'venue-star-rating__stars';
        stars.setAttribute('aria-hidden', 'true');
        base.before(stars);
        stars.append(base, fill);
    }

    compact?.setAttribute('hidden', '');

    const label = String(rating.getAttribute('aria-label') || '').trim();
    const match = label.match(/(\d+(?:[.,]\d+)?)/u);
    const exactValue = match ? Number(match[1].replace(',', '.')) : 0;
    const roundedValue = Number.isFinite(exactValue) ? Math.round(exactValue) : 0;

    let value = rating.querySelector('.venue-star-rating__value');
    if (!value) {
        value = document.createElement('span');
        value.className = 'venue-star-rating__value';
        value.setAttribute('aria-hidden', 'true');
        rating.append(value);
    }

    value.textContent = String(roundedValue);
}
