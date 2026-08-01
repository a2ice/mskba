document.addEventListener('DOMContentLoaded', () => {
    initVenueAnchors();
    initVenueGalleryModal();
    initVenueDayModal();
    initVenueNearbyModal();
});

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

    const sections = [...new Set(links
        .map((link) => document.querySelector(link.getAttribute('href') || ''))
        .filter(Boolean))];

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
            const isActive = link.getAttribute('href') === `#${visible.target.id}`;

            link.classList.toggle('is-active', isActive);

            if (isActive && link.closest('[data-venue-mobile-nav]') && window.matchMedia('(max-width: 900px)').matches) {
                const navigation = link.closest('[data-venue-mobile-nav]');
                const targetLeft = link.offsetLeft - ((navigation.clientWidth - link.offsetWidth) / 2);

                navigation.scrollTo({ left: Math.max(0, targetLeft), behavior: 'smooth' });
            }
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
    const tags = modal.querySelector('[data-venue-gallery-tags]');
    const tagLinks = modal.querySelector('[data-venue-gallery-tag-links]');
    const caption = modal.querySelector('[data-venue-gallery-caption]');
    const captionToggle = modal.querySelector('[data-venue-gallery-caption-toggle]');
    const captionToggleIcon = modal.querySelector('[data-venue-gallery-caption-toggle-icon]');
    const closeButtons = Array.from(modal.querySelectorAll('[data-venue-gallery-close]'));
    const prevButton = modal.querySelector('[data-venue-gallery-prev]');
    const nextButton = modal.querySelector('[data-venue-gallery-next]');
    let currentIndex = 0;

    if (items.length < 2) {
        prevButton?.setAttribute('hidden', '');
        nextButton?.setAttribute('hidden', '');
    }

    const show = (index) => {
        currentIndex = (index + items.length) % items.length;
        const item = items[currentIndex];

        image.src = item.dataset.url || '';
        image.alt = item.dataset.title || '';
        if (title) title.textContent = item.dataset.title || '';
        description.textContent = item.dataset.description || '';
        description.hidden = !description.textContent;
        let photoTags = [];
        if (tags) {
            tags.replaceChildren();
            tagLinks?.replaceChildren();
            try {
                photoTags = JSON.parse(item.dataset.tags || '[]');
            } catch {
                photoTags = [];
            }
            photoTags.forEach((tag, tagIndex) => {
                const marker = document.createElement('span');
                marker.className = 'event-photo-tag';
                marker.dataset.photoTagIndex = String(tagIndex);
                marker.style.setProperty('--tag-x', `${tag.x}%`);
                marker.style.setProperty('--tag-y', `${tag.y}%`);
                marker.setAttribute('aria-label', `Отмечен участник ${tag.name}`);

                const demoStatistics = document.createElement('span');
                demoStatistics.className = 'event-photo-tag__statistics';
                demoStatistics.textContent = [
                    `Броски: ${tagIndex + 2}/${tagIndex + 5}`,
                    `Подборы: ${tagIndex + 3}`,
                    `Передачи: ${tagIndex + 1}`,
                    `Потери: ${tagIndex}`,
                    `Фолы: ${tagIndex + 1}`,
                ].join('\n');
                marker.append(demoStatistics);
                tags.append(marker);

                if (tagLinks) {
                    const link = document.createElement('button');
                    link.type = 'button';
                    link.textContent = tag.name;
                    link.setAttribute('aria-pressed', 'false');
                    link.addEventListener('click', () => {
                        const willShow = !marker.classList.contains('is-visible');
                        tags.querySelectorAll('.event-photo-tag').forEach((item) => item.classList.remove('is-visible'));
                        tagLinks.querySelectorAll('button').forEach((item) => item.setAttribute('aria-pressed', 'false'));
                        marker.classList.toggle('is-visible', willShow);
                        link.setAttribute('aria-pressed', String(willShow));
                    });
                    tagLinks.append(link);
                }
            });
            tagLinks?.toggleAttribute('hidden', photoTags.length === 0);
        }
        caption?.toggleAttribute('hidden', !description.textContent && photoTags.length === 0);
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

    captionToggle?.addEventListener('click', () => {
        const collapsed = caption?.classList.toggle('is-collapsed') || false;
        captionToggle.setAttribute('aria-expanded', String(!collapsed));
        captionToggle.setAttribute('aria-label', collapsed
            ? 'Развернуть информацию о фотографии'
            : 'Свернуть информацию о фотографии');
        captionToggleIcon?.classList.toggle('ti-chevron-down', !collapsed);
        captionToggleIcon?.classList.toggle('ti-chevron-up', collapsed);
    });

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
