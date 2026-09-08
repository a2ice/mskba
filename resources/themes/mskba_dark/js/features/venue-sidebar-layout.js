function initVenueSidebarLayout() {
    const venuePage = document.querySelector('.venue-show');
    const sidebar = document.querySelector('[data-mobile-section-sidebar]');

    if (!venuePage || !sidebar) {
        return;
    }

    initVenueSidebarAccordion(sidebar);
    placePageNavigationBeforeActivities(venuePage);
}

function initVenueSidebarAccordion(sidebar) {
    const panel = sidebar.querySelector('.section-sidebar-layout__panel');
    const blocks = Array.from(panel?.children || [])
        .filter((node) => node.classList?.contains('section-sidebar-block'));

    if (blocks.length === 0) {
        return;
    }

    const labels = new Map([
        ['Площадка', 'Навигация'],
        ['Состояние', 'Информация'],
        ['Управление', 'Управление'],
    ]);

    const items = blocks.map((block, index) => {
        const heading = block.querySelector(':scope > .section-sidebar-block__title');
        if (!heading) {
            return null;
        }

        const originalLabel = heading.textContent.trim();
        const label = labels.get(originalLabel) || originalLabel;
        const content = document.createElement('div');
        const contentId = `venue-sidebar-accordion-panel-${index + 1}`;

        content.className = 'venue-sidebar-accordion__content';
        content.id = contentId;

        while (heading.nextSibling) {
            content.append(heading.nextSibling);
        }

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'venue-sidebar-accordion__trigger';
        trigger.setAttribute('aria-controls', contentId);
        trigger.innerHTML = `<span></span><i class="ti ti-chevron-down" aria-hidden="true"></i>`;
        trigger.querySelector('span').textContent = label;

        heading.replaceWith(trigger);
        block.append(content);
        block.classList.add('venue-sidebar-accordion__item');

        return { block, trigger, content, isDefault: index === 0 };
    }).filter(Boolean);

    const setOpen = (item, open) => {
        item.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        item.content.hidden = !open;
        item.block.classList.toggle('is-open', open);
    };

    items.forEach((item) => setOpen(item, item.isDefault));

    items.forEach((item) => {
        item.trigger.addEventListener('click', () => {
            const willOpen = item.trigger.getAttribute('aria-expanded') !== 'true';

            if (willOpen) {
                items.forEach((candidate) => setOpen(candidate, candidate === item));
                return;
            }

            setOpen(item, false);
        });
    });
}

function placePageNavigationBeforeActivities(venuePage) {
    const navigation = venuePage.querySelector('.venue-anchor-nav');
    if (!navigation) {
        return;
    }

    const move = () => {
        const activities = venuePage.querySelector('[data-venue-activities]');
        if (!activities) {
            return false;
        }

        if (activities.previousElementSibling !== navigation) {
            activities.before(navigation);
        }

        return true;
    };

    if (move()) {
        return;
    }

    const observer = new MutationObserver(() => {
        if (move()) {
            observer.disconnect();
        }
    });

    observer.observe(venuePage, { childList: true });
}

initVenueSidebarLayout();
