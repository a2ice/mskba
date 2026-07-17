document.addEventListener('DOMContentLoaded', () => {
    initVenueAnchors();
    initVenueGalleryModal();
    initVenueDayModal();
    initVenueNearbyModal();
    initVenueHeroGallery();
});

function initVenueHeroGallery() {
    const mainImage = document.querySelector('[data-venue-hero-image]');
    const thumbnails = Array.from(document.querySelectorAll('[data-venue-hero-thumbnail]'));

    if (!mainImage || thumbnails.length === 0) {
        return;
    }

    thumbnails.forEach((thumbnail) => {
        thumbnail.addEventListener('click', () => {
            mainImage.src = thumbnail.dataset.url || '';
            mainImage.alt = thumbnail.dataset.alt || '';

            thumbnails.forEach((item) => {
                const isActive = item === thumbnail;
                item.classList.toggle('is-active', isActive);
                item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        });
    });
}

function initVenueAnchors() {
    const links = Array.from(document.querySelectorAll('[data-venue-anchor-link]'));
    const scrollButtons = Array.from(document.querySelectorAll('[data-venue-scroll-target]'));

    if (links.length === 0 && scrollButtons.length === 0) {
        return;
    }

    const scrollToSection = (id) => {
        const section = document.getElementById(id);
        if (!section) {
            return;
        }

        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href') || '';
            if (!href.startsWith('#')) {
                return;
            }

            event.preventDefault();
            scrollToSection(href.slice(1));
        });
    });

    scrollButtons.forEach((button) => {
        button.addEventListener('click', () => {
            scrollToSection(button.dataset.venueScrollTarget || '');
        });
    });

    const sections = links
        .map((link) => document.querySelector(link.getAttribute('href') || ''))
        .filter(Boolean);

    if (sections.length === 0 || !('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

        if (!visible) {
            return;
        }

        links.forEach((link) => {
            link.classList.toggle('is-active', link.getAttribute('href') === `#${visible.target.id}`);
        });
    }, {
        rootMargin: '-30% 0px -55% 0px',
        threshold: [0.1, 0.35, 0.6],
    });

    sections.forEach((section) => observer.observe(section));
}

function initVenueNearbyModal() {
    const modal = document.querySelector('[data-venue-nearby-modal]');
    const openButton = document.querySelector('[data-venue-nearby-open]');

    if (!modal || !openButton) {
        return;
    }

    const closeButtons = Array.from(modal.querySelectorAll('[data-venue-nearby-close]'));

    const close = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
        openButton.focus();
    };

    openButton.addEventListener('click', () => {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        modal.querySelector('[data-venue-nearby-close]')?.focus();
    });

    closeButtons.forEach((button) => button.addEventListener('click', close));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
}

function initVenueGalleryModal() {
    const modal = document.querySelector('[data-venue-gallery-modal]');
    const items = Array.from(document.querySelectorAll('[data-venue-gallery-item]'));

    if (!modal || items.length === 0) {
        return;
    }

    const image = modal.querySelector('[data-venue-gallery-image]');
    const title = modal.querySelector('[data-venue-gallery-title]');
    const description = modal.querySelector('[data-venue-gallery-description]');
    const closeButtons = Array.from(modal.querySelectorAll('[data-venue-gallery-close]'));
    const prevButton = modal.querySelector('[data-venue-gallery-prev]');
    const nextButton = modal.querySelector('[data-venue-gallery-next]');
    let currentIndex = 0;

    const show = (index) => {
        currentIndex = (index + items.length) % items.length;
        const item = items[currentIndex];

        image.src = item.dataset.url || '';
        image.alt = item.dataset.title || '';
        title.textContent = item.dataset.title || '';
        description.textContent = item.dataset.description || '';
        description.hidden = !description.textContent;
    };

    const open = (index) => {
        show(index);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    };

    const close = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
        image.src = '';
    };

    items.forEach((item, index) => {
        item.addEventListener('click', () => open(index));
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', close);
    });

    prevButton?.addEventListener('click', () => show(currentIndex - 1));
    nextButton?.addEventListener('click', () => show(currentIndex + 1));

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            close();
        }

        if (event.key === 'ArrowLeft') {
            show(currentIndex - 1);
        }

        if (event.key === 'ArrowRight') {
            show(currentIndex + 1);
        }
    });
}

function initVenueDayModal() {
    const modal = document.querySelector('[data-venue-day-modal]');
    const cards = Array.from(document.querySelectorAll('[data-venue-day-card]'));

    if (!modal || cards.length === 0) {
        return;
    }

    const title = modal.querySelector('[data-venue-day-modal-title]');
    const weekday = modal.querySelector('[data-venue-day-modal-weekday]');
    const intervalsContainer = modal.querySelector('[data-venue-day-modal-intervals]');
    const closeButtons = Array.from(modal.querySelectorAll('[data-venue-day-modal-close]'));

    const close = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
    };

    const open = (card) => {
        const intervals = parseIntervals(card.dataset.intervals || '[]');

        title.textContent = card.dataset.label || 'День расписания';
        weekday.textContent = [
            card.dataset.weekday || '',
            card.dataset.isToday === '1' ? 'Сегодня' : '',
        ].filter(Boolean).join(' · ');
        intervalsContainer.innerHTML = renderIntervals(intervals, card.dataset.isClosed === '1');

        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    };

    cards.forEach((card) => {
        card.addEventListener('click', () => open(card));
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
}

function parseIntervals(value) {
    try {
        const intervals = JSON.parse(value);

        return Array.isArray(intervals) ? intervals : [];
    } catch (error) {
        return [];
    }
}

function renderIntervals(intervals, isClosed) {
    if (isClosed || intervals.length === 0) {
        return '<div class="venue-day-modal__empty">На этот день рабочие интервалы не указаны.</div>';
    }

    const items = intervals
        .map((interval) => {
            const startsAt = escapeHtml(interval.startsAt || '');
            const endsAt = escapeHtml(interval.endsAt || '');

            return `<div class="venue-day-modal__interval"><span>${startsAt}</span><span>${endsAt}</span></div>`;
        })
        .join('');

    return `<div class="venue-day-modal__interval-list">${items}</div>`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
