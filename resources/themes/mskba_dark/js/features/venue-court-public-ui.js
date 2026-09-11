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

    const dropdowns = Array.from(document.querySelectorAll('[data-venue-court-dropdown]'));

    dropdowns.forEach((dropdown) => {
        dropdown.addEventListener('toggle', () => {
            if (!dropdown.open) return;

            dropdowns.forEach((otherDropdown) => {
                if (otherDropdown !== dropdown) otherDropdown.removeAttribute('open');
            });
        });
    });

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (dropdown.open && !dropdown.contains(event.target)) {
                dropdown.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        dropdowns.forEach((dropdown) => dropdown.removeAttribute('open'));
    });
});
