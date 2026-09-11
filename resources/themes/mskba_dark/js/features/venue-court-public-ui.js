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

        const openingState = mediaStatus.querySelector('.venue-opening-state');
        if (openingState) openingState.hidden = true;
    }

    document.querySelectorAll('[data-venue-court-picker-modal]').forEach((picker) => {
        const modal = picker.closest('[data-modal]');
        if (modal && modal.parentElement !== document.body) {
            document.body.append(modal);
        }
    });

    document.querySelectorAll('[data-venue-court-dropdown-trigger]').forEach((trigger) => {
        const contextRoot = trigger.closest('[data-venue-court-context]');
        const dropdown = contextRoot?.querySelector('[data-venue-court-dropdown]');
        if (!dropdown) return;

        const close = ({ restoreFocus = false } = {}) => {
            dropdown.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            contextRoot.classList.remove('is-open');
            if (restoreFocus) trigger.focus();
        };

        const open = () => {
            document.querySelectorAll('[data-venue-court-dropdown]').forEach((other) => {
                if (other === dropdown || other.hidden) return;
                const otherContext = other.closest('[data-venue-court-context]');
                const otherTrigger = otherContext?.querySelector('[data-venue-court-dropdown-trigger]');
                other.hidden = true;
                otherContext?.classList.remove('is-open');
                otherTrigger?.setAttribute('aria-expanded', 'false');
            });

            dropdown.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            contextRoot.classList.add('is-open');
        };

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            dropdown.hidden ? open() : close();
        });

        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open();
                dropdown.querySelector('a')?.focus();
            }
        });

        dropdown.addEventListener('click', (event) => event.stopPropagation());

        document.addEventListener('click', (event) => {
            if (!contextRoot.contains(event.target)) close();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !dropdown.hidden) close({ restoreFocus: true });
        });
    });

    if (!context) {
        const openingState = document.querySelector('.venue-hero__media-status .venue-opening-state');
        const openingLabel = openingState?.querySelector('strong')?.textContent?.trim();
        if (openingState && openingLabel) {
            openingState.setAttribute('aria-label', `Площадка ${openingLabel.toLowerCase()}`);
            openingState.setAttribute('tabindex', '0');
            openingState.classList.add('ui-tooltip-source', 'ui-tooltip-source--title', 'ui-tooltip-source--icon');
            openingState.dataset.tooltip = openingLabel;
        }
    }
});
