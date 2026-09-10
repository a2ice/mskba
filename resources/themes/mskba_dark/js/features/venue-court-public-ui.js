document.addEventListener('DOMContentLoaded', () => {
    const context = document.querySelector('[data-venue-court-context]');
    const mediaStatus = document.querySelector('.venue-hero__media-status');

    if (context && mediaStatus) {
        const typeBadge = mediaStatus.querySelector('.venue-pill');
        if (typeBadge) {
            typeBadge.after(context);
        } else {
            mediaStatus.prepend(context);
        }
    }

    document.querySelectorAll('[data-venue-court-picker-modal]').forEach((picker) => {
        const modal = picker.closest('[data-modal]');
        if (modal && modal.parentElement !== document.body) {
            document.body.append(modal);
        }
    });

    const openingState = document.querySelector('.venue-hero__media-status .venue-opening-state');
    const openingLabel = openingState?.querySelector('strong')?.textContent?.trim();
    if (openingState && openingLabel) {
        openingState.setAttribute('title', openingLabel);
        openingState.setAttribute('aria-label', `Площадка ${openingLabel.toLowerCase()}`);
        openingState.dataset.tooltipVariant = 'title';
    }
});
