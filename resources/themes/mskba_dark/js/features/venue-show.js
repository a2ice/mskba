document.addEventListener('DOMContentLoaded', () => {
    initVenueAnchors();
    initVenueHeroSlider();
    initVenueGalleryModal();
    initVenueDayModal();
    initVenueOccupancyModal();
});

function initVenueAnchors() {
    const links = Array.from(document.querySelectorAll('[data-venue-anchor-link]'));
    const scrollButtons = Array.from(document.querySelectorAll('[data-venue-scroll-target]'));
    const pageNavigation = document.querySelector('[data-venue-anchor-nav]');

    if (links.length === 0 && scrollButtons.length === 0) {
        return;
    }

    const updateHash = (id, mode = 'replace') => {
        if (!id || !window.history?.replaceState) {
            return;
        }

        const url = new URL(window.location.href);
        url.hash = id;
        window.history[mode === 'push' ? 'pushState' : 'replaceState'](null, '', url);
    };

    let activeSectionId = null;

    const revealActiveLink = (link) => {
        const navigation = link.closest('[data-venue-anchor-nav], [data-venue-mobile-nav]');
        if (!navigation || navigation.getClientRects().length === 0 || navigation.scrollWidth <= navigation.clientWidth) {
            return;
        }

        const navigationRect = navigation.getBoundingClientRect();
        const linkRect = link.getBoundingClientRect();
        const edgeInset = 8;
        const isFullyVisible = linkRect.left >= navigationRect.left + edgeInset
            && linkRect.right <= navigationRect.right - edgeInset;

        if (isFullyVisible) {
            return;
        }

        const navigationLinks = Array.from(navigation.querySelectorAll('[data-venue-anchor-link]'));
        const activeIndex = navigationLinks.indexOf(link);
        const leadingLink = navigationLinks[Math.max(0, activeIndex - 1)] || link;
        const maxScrollLeft = Math.max(0, navigation.scrollWidth - navigation.clientWidth);
        const targetLeft = Math.min(maxScrollLeft, Math.max(0, leadingLink.offsetLeft - edgeInset));

        navigation.scrollTo({ left: targetLeft, behavior: 'smooth' });
    };

    const setActiveSection = (id, updateUrl = true) => {
        const sectionChanged = activeSectionId !== id;
        const activeLinks = [];
        activeSectionId = id;

        links.forEach((link) => {
            const isActive = link.getAttribute('href') === `#${id}`;

            link.classList.toggle('is-active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'location');
                activeLinks.push(link);
            } else {
                link.removeAttribute('aria-current');
            }
        });

        if (sectionChanged && window.matchMedia('(max-width: 1024px)').matches) {
            window.requestAnimationFrame(() => activeLinks.forEach(revealActiveLink));
        }

        if (updateUrl && window.location.hash !== `#${id}`) {
            updateHash(id);
        }
    };

    if (pageNavigation) {
        let stickyFrame = null;
        const updateStickyState = () => {
            stickyFrame = null;

            if (!window.matchMedia('(max-width: 1024px)').matches) {
                pageNavigation.classList.remove('is-stuck');
                return;
            }

            const stickyTop = Number.parseFloat(window.getComputedStyle(pageNavigation).top) || 0;
            pageNavigation.classList.toggle(
                'is-stuck',
                pageNavigation.getBoundingClientRect().top <= stickyTop + 1,
            );
        };
        const requestStickyUpdate = () => {
            if (stickyFrame !== null) {
                return;
            }

            stickyFrame = window.requestAnimationFrame(updateStickyState);
        };

        window.addEventListener('scroll', requestStickyUpdate, { passive: true });
        window.addEventListener('resize', requestStickyUpdate, { passive: true });
        updateStickyState();
    }

    const scrollToSection = (id, pushHistory = false) => {
        const section = document.getElementById(id);
        if (!section) {
            return;
        }

        if (pushHistory) {
            updateHash(id, window.location.hash === `#${id}` ? 'replace' : 'push');
        }
        setActiveSection(id, false);
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href') || '';
            if (!href.startsWith('#')) {
                return;
            }

            event.preventDefault();
            scrollToSection(href.slice(1), true);
        });
    });

    scrollButtons.forEach((button) => {
        button.addEventListener('click', () => {
            scrollToSection(button.dataset.venueScrollTarget || '', true);
        });
    });

    const sections = [...new Set(links
        .map((link) => document.querySelector(link.getAttribute('href') || ''))
        .filter(Boolean))];

    if (sections.length === 0) {
        return;
    }

    let frame = null;
    const updateActiveFromScroll = () => {
        frame = null;
        const activationLine = Math.max(120, window.innerHeight * 0.3);
        const active = sections
            .filter((section) => section.getBoundingClientRect().top <= activationLine)
            .at(-1);

        if (active) {
            setActiveSection(active.id);
        }
    };
    const requestActiveUpdate = () => {
        if (frame === null) {
            frame = window.requestAnimationFrame(updateActiveFromScroll);
        }
    };

    window.addEventListener('scroll', requestActiveUpdate, { passive: true });
    window.addEventListener('resize', requestActiveUpdate);
    window.addEventListener('popstate', () => {
        const id = decodeURIComponent(window.location.hash.slice(1));
        if (id) {
            scrollToSection(id);
        }
    });
    requestActiveUpdate();
}

function initVenueHeroSlider() {
    const slider = document.querySelector('[data-venue-hero-slider]');
    const slides = Array.from(slider?.querySelectorAll('[data-venue-hero-slide]') || []);

    if (!slider || slides.length === 0) {
        return;
    }

    const previousButton = slider.querySelector('[data-venue-hero-prev]');
    const nextButton = slider.querySelector('[data-venue-hero-next]');
    let currentIndex = 0;

    const show = (requestedIndex) => {
        currentIndex = (requestedIndex + slides.length) % slides.length;
        slides.forEach((slide, index) => {
            const isActive = index === currentIndex;

            slide.classList.toggle('is-active', isActive);
            slide.setAttribute('aria-hidden', String(!isActive));
            slide.tabIndex = isActive ? 0 : -1;
        });
    };

    previousButton?.addEventListener('click', () => show(currentIndex - 1));
    nextButton?.addEventListener('click', () => show(currentIndex + 1));
    slider.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        event.preventDefault();
        show(currentIndex + (event.key === 'ArrowRight' ? 1 : -1));
        slides[currentIndex].focus();
    });

    show(0);
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
    const pagination = modal.querySelector('[data-venue-gallery-pagination]');
    let currentIndex = 0;

    const clearTagSelection = () => {
        tags?.querySelectorAll('.event-photo-tag').forEach((marker) => {
            marker.classList.remove('is-visible', 'is-statistics-visible');
            marker.setAttribute('aria-expanded', 'false');
        });
        tagLinks?.querySelectorAll('button').forEach((link) => link.setAttribute('aria-pressed', 'false'));
    };

    const selectTag = (marker) => {
        clearTagSelection();
        marker.classList.add('is-visible');
        tagLinks
            ?.querySelector(`[data-photo-tag-index="${marker.dataset.photoTagIndex}"]`)
            ?.setAttribute('aria-pressed', 'true');
    };

    tags?.addEventListener('click', (event) => {
        if (event.target.closest('.event-photo-tag')) {
            return;
        }

        const hiddenMarkerAtPoint = Array.from(tags.querySelectorAll('.event-photo-tag:not(.is-visible)'))
            .find((marker) => {
                const bounds = marker.getBoundingClientRect();

                return event.clientX >= bounds.left
                    && event.clientX <= bounds.right
                    && event.clientY >= bounds.top
                    && event.clientY <= bounds.bottom;
            });

        clearTagSelection();

        if (hiddenMarkerAtPoint) {
            selectTag(hiddenMarkerAtPoint);
        }
    });

    if (items.length < 2) {
        prevButton?.setAttribute('hidden', '');
        nextButton?.setAttribute('hidden', '');
    }

    if (pagination) {
        pagination.toggleAttribute('hidden', items.length < 2);
        items.forEach((item, index) => {
            const bullet = document.createElement('button');
            bullet.type = 'button';
            bullet.setAttribute('aria-label', `Открыть фотографию ${index + 1}`);
            bullet.addEventListener('click', () => show(index));
            pagination.append(bullet);
        });
    }

    const show = (index) => {
        currentIndex = (index + items.length) % items.length;
        const item = items[currentIndex];

        pagination?.querySelectorAll('button').forEach((bullet, bulletIndex) => {
            bullet.setAttribute('aria-current', String(bulletIndex === currentIndex));
        });

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
                marker.setAttribute('aria-expanded', 'false');
                marker.setAttribute('role', 'button');
                marker.tabIndex = 0;

                const statisticsPanel = document.createElement('span');
                statisticsPanel.className = 'event-photo-tag__statistics';

                const participantName = document.createElement('strong');
                participantName.className = 'event-photo-tag__participant-name';
                participantName.textContent = tag.name;
                statisticsPanel.append(participantName);

                if (tag.statistics) {
                    const statistics = document.createElement('span');
                    statistics.className = 'event-photo-tag__statistics-values';
                    [
                        ['Броски:', `${tag.statistics.shots_made}/${tag.statistics.shots_attempted}`],
                        ['Подборы:', tag.statistics.rebounds],
                        ['Передачи:', tag.statistics.assists],
                    ].forEach(([label, value]) => {
                        const row = document.createElement('span');
                        row.className = 'event-photo-tag__statistics-row';
                        const labelElement = document.createElement('span');
                        labelElement.textContent = label;
                        const valueElement = document.createElement('strong');
                        valueElement.textContent = String(value);
                        row.append(labelElement, valueElement);
                        statistics.append(row);
                    });
                    statisticsPanel.append(statistics);
                }

                if (tag.details_url) {
                    const detailsLink = document.createElement('a');
                    detailsLink.className = 'event-photo-tag__details-link';
                    detailsLink.href = tag.details_url;
                    detailsLink.textContent = 'Подробнее';
                    detailsLink.addEventListener('click', (event) => event.stopPropagation());
                    statisticsPanel.append(detailsLink);
                }

                marker.append(statisticsPanel);
                const toggleStatistics = () => {
                    const isVisible = marker.classList.toggle('is-statistics-visible');
                    marker.setAttribute('aria-expanded', String(isVisible));
                };
                marker.addEventListener('click', toggleStatistics);
                marker.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    event.preventDefault();
                    toggleStatistics();
                });
                tags.append(marker);

                if (tagLinks) {
                    const link = document.createElement('button');
                    link.type = 'button';
                    link.dataset.photoTagIndex = String(tagIndex);
                    link.textContent = tag.name;
                    link.setAttribute('aria-pressed', 'false');
                    link.addEventListener('click', () => {
                        const willShow = !marker.classList.contains('is-visible');

                        if (willShow) {
                            selectTag(marker);
                        } else {
                            clearTagSelection();
                        }
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

function initVenueOccupancyModal() {
    const content = document.querySelector('[data-venue-occupancy-modal]');
    const dayButtons = Array.from(document.querySelectorAll('[data-venue-occupancy-day]'));

    if (!content || dayButtons.length === 0) {
        return;
    }

    const modal = content.closest('[data-modal]');
    const title = content.querySelector('[data-venue-occupancy-title]');
    const date = content.querySelector('[data-venue-occupancy-date]');
    const panels = Array.from(content.querySelectorAll('[data-venue-occupancy-panel]'));
    const previousButton = content.querySelector('[data-venue-occupancy-prev]');
    const nextButton = content.querySelector('[data-venue-occupancy-next]');
    let currentIndex = 0;

    const show = (requestedIndex) => {
        currentIndex = Math.max(0, Math.min(dayButtons.length - 1, requestedIndex));
        const dayButton = dayButtons[currentIndex];
        const label = dayButton.dataset.dayLabel || '';
        const weekday = dayButton.dataset.dayWeekday || '';
        const isToday = dayButton.dataset.isToday === '1';

        if (title) {
            title.textContent = label ? 'Занятые слоты · ' + label : 'Занятые слоты';
        }
        if (date) {
            date.textContent = [weekday, isToday ? 'Сегодня' : ''].filter(Boolean).join(' · ');
        }

        panels.forEach((panel, panelIndex) => {
            panel.hidden = panelIndex !== currentIndex;
        });

        if (previousButton) previousButton.disabled = currentIndex === 0;
        if (nextButton) nextButton.disabled = currentIndex === dayButtons.length - 1;
    };

    dayButtons.forEach((button, index) => {
        button.addEventListener('click', () => show(index));
    });
    previousButton?.addEventListener('click', () => show(currentIndex - 1));
    nextButton?.addEventListener('click', () => show(currentIndex + 1));

    document.addEventListener('keydown', (event) => {
        if (!modal?.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'ArrowLeft') show(currentIndex - 1);
        if (event.key === 'ArrowRight') show(currentIndex + 1);
    });

    show(0);
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
