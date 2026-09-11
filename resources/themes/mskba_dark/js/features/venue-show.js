document.addEventListener('DOMContentLoaded', () => {
    initVenueAnchors();
    initVenueHeroSlider();
    initVenueGalleryModal();
    initVenueDayModal();
    initVenueOccupancyModal();
    initVenueInlineRental();
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

        const activeTimeline = panels[currentIndex]?.querySelector('[data-venue-rental-timeline]');
        window.requestAnimationFrame(() => {
            activeTimeline?.querySelector('[data-venue-occupied-slot]')?.scrollIntoView({ block: 'center' });
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

function initVenueInlineRental() {
    const root = document.querySelector('[data-venue-occupancy-modal]');
    if (!root?.dataset.venueRental) return;

    let config;
    try {
        config = JSON.parse(root.dataset.venueRental);
    } catch (_error) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const authTrigger = document.querySelector('[data-venue-booking-auth]');
    const detailsTrigger = document.querySelector('[data-venue-booking-details-trigger]');
    const detailsModal = document.querySelector('[data-venue-booking-details-modal]');
    let activeCell = null;
    let activeBooking = null;

    const errorMessage = (payload, fallback) => payload?.message
        || Object.values(payload?.errors || {}).flat()[0]
        || fallback;
    const uuid = () => window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const money = new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
    const currencyLabel = (currency) => String(currency || 'RUB').toUpperCase() === 'RUB' ? 'руб.' : String(currency).toUpperCase();

    const updatePrice = (form) => {
        const price = form?.querySelector('[data-venue-rental-price]');
        const cell = form?.closest('[data-venue-rental-cell]');
        const panel = form?.closest('[data-venue-occupancy-panel]');
        if (!price || !cell || !panel) return;

        const duration = Number(form.elements.duration_minutes.value || 0);
        const scope = form.elements.scope.value;
        const isWhole = scope === 'whole';
        const fallback = isWhole ? config.wholePricePerStepMinor : config.halfPricePerStepMinor;
        if (fallback == null || duration < 1) {
            price.textContent = 'Цена не указана';
            return;
        }

        const stepDate = new Date(`${panel.dataset.dayDate}T${cell.dataset.start}:00Z`);
        let amountMinor = 0;
        for (let offset = 0; offset < duration; offset += config.timeStepMinutes) {
            const current = new Date(stepDate.getTime() + offset * 60000);
            const dayOfWeek = current.getUTCDay() || 7;
            const startsAt = `${String(current.getUTCHours()).padStart(2, '0')}:${String(current.getUTCMinutes()).padStart(2, '0')}`;
            const custom = config.priceOverrides?.[`${dayOfWeek}|${startsAt}`]?.[isWhole ? 'whole' : 'half'];
            amountMinor += Number(custom ?? fallback);
        }
        price.textContent = amountMinor === 0 ? 'Бесплатно' : `${money.format(amountMinor / 100)} ${currencyLabel(config.currency)}`;
    };

    const buildForm = (cell) => {
        const available = Number(cell.dataset.availableMinutes || 0);
        const durations = [];
        for (let value = config.minimumDurationMinutes; value <= Math.min(config.maximumDurationMinutes, available); value += config.timeStepMinutes) {
            durations.push(value);
        }
        const scopeOptions = (config.scopes || [])
            .map((scope) => `<option value="${escapeHtml(scope.value)}">${escapeHtml(scope.label)}</option>`)
            .join('');
        const durationOptions = durations
            .map((duration) => `<option value="${duration}">${duration} мин</option>`)
            .join('');

        return `<form class="venue-rental-cell__form" data-venue-rental-form>
            <header class="venue-rental-cell__form-top">
                <div class="venue-rental-cell__price">
                    <span>Стоимость</span>
                    <strong data-venue-rental-price>Рассчитываем…</strong>
                    <button type="button" class="ui-tooltip-trigger" aria-label="Подсказка: цена может быть скорректирована в процессе отправки заявки" data-tooltip="Цена может быть скорректирована в процессе отправки заявки.">?</button>
                </div>
                <button type="button" class="venue-rental-cell__close" data-venue-rental-close aria-label="Закрыть форму"><i class="ti ti-x" aria-hidden="true"></i></button>
            </header>
            <div class="venue-rental-cell__controls">
                <label><span>Длительность</span><select class="form-select" name="duration_minutes" required>${durationOptions}</select></label>
                <label><span>Зона</span><select class="form-select" name="scope" required>${scopeOptions}</select></label>
                <button type="submit" class="btn btn--primary">Подать заявку</button>
            </div>
            <p class="venue-rental-cell__message" data-venue-rental-message aria-live="polite"></p>
        </form>`;
    };

    const closeCell = (cell) => {
        cell?.querySelector('[data-venue-rental-form]')?.remove();
        cell?.classList.remove('is-expanded', 'is-loading');
        cell?.querySelector('[data-venue-rental-cell-open]')?.setAttribute('aria-expanded', 'false');
        if (activeCell === cell) activeCell = null;
    };

    const openCell = (cell) => {
        if (!cell || cell.classList.contains('is-disabled')) return;
        if (activeCell && activeCell !== cell) closeCell(activeCell);
        if (!cell.querySelector('[data-venue-rental-form]')) cell.insertAdjacentHTML('beforeend', buildForm(cell));
        cell.classList.add('is-expanded');
        cell.querySelector('[data-venue-rental-cell-open]')?.setAttribute('aria-expanded', 'true');
        activeCell = cell;
        updatePrice(cell.querySelector('[data-venue-rental-form]'));
        window.requestAnimationFrame(() => {
            cell.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            cell.querySelector('select')?.focus({ preventScroll: true });
        });
    };

    root.addEventListener('click', (event) => {
        const open = event.target.closest('[data-venue-rental-cell-open]');
        if (open) openCell(open.closest('[data-venue-rental-cell]'));
        const close = event.target.closest('[data-venue-rental-close]');
        if (close) closeCell(close.closest('[data-venue-rental-cell]'));
        const details = event.target.closest('[data-venue-booking-details]');
        if (details) openBookingDetails(details.dataset);
    });

    root.addEventListener('change', (event) => {
        const form = event.target.closest('[data-venue-rental-form]');
        if (form) updatePrice(form);
    });

    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-venue-rental-form]');
        if (!form) return;
        event.preventDefault();
        const cell = form.closest('[data-venue-rental-cell]');
        const panel = form.closest('[data-venue-occupancy-panel]');
        const message = form.querySelector('[data-venue-rental-message]');
        const submit = form.querySelector('[type="submit"]');
        const formData = new FormData(form);

        if (!config.authenticated) {
            const redirect = new URL(window.location.href);
            redirect.searchParams.set('booking_resume', '1');
            redirect.searchParams.set('booking_date', panel.dataset.dayDate);
            redirect.searchParams.set('booking_start', cell.dataset.start);
            redirect.searchParams.set('booking_duration', formData.get('duration_minutes'));
            redirect.searchParams.set('booking_scope', formData.get('scope'));
            authTrigger?.setAttribute('data-auth-redirect-url', redirect.pathname + redirect.search + redirect.hash);
            authTrigger?.click();
            return;
        }
        if (!config.confirmedAccount) {
            message.textContent = 'Для заявки нужен подтверждённый аккаунт.';
            return;
        }

        cell.classList.add('is-loading');
        submit.disabled = true;
        message.textContent = 'Проверяем доступность и стоимость…';
        try {
            const quoteResponse = await fetch(config.quoteUrl, {
                method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: new URLSearchParams({
                    venue_court_id: config.courtId,
                    starts_at: `${panel.dataset.dayDate}T${cell.dataset.start}`,
                    duration_minutes: formData.get('duration_minutes'),
                    scope: formData.get('scope'),
                }),
            });
            const quote = await quoteResponse.json();
            if (!quoteResponse.ok) throw new Error(errorMessage(quote, 'Не удалось рассчитать аренду.'));
            message.textContent = `Стоимость: ${(quote.amount_minor / 100).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ${currencyLabel(quote.currency)}. Отправляем заявку…`;

            const bookingResponse = await fetch(config.requestUrl, {
                method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: new URLSearchParams({ quote_id: quote.quote_id, idempotency_key: uuid() }),
            });
            const booking = await bookingResponse.json();
            if (!bookingResponse.ok) throw new Error(errorMessage(booking, 'Не удалось отправить заявку.'));
            renderAsBooking(cell, booking, panel.dataset.dayDate, quote);
            openBookingDetails({
                bookingId: booking.booking_id,
                bookingStatusUrl: booking.status_url,
                bookingDetailsUrl: booking.details_url,
            }, booking);
        } catch (error) {
            message.textContent = error.message;
            cell.classList.remove('is-loading');
            submit.disabled = false;
        }
    });

    function renderAsBooking(cell, booking, _dayDate, quote) {
        const start = cell.dataset.start;
        const end = new Date(quote.ends_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        cell.className = 'venue-occupancy-slot is-occupied';
        cell.dataset.venueOccupiedSlot = '';
        cell.removeAttribute('data-venue-rental-cell');
        cell.innerHTML = `<time>${escapeHtml(start)}–${escapeHtml(end)}</time><div>
            <span class="venue-occupancy-slot__booking"><strong>Бронирование</strong>
            <span class="venue-occupancy-slot__status venue-occupancy-slot__status--${escapeHtml(booking.status)}"><span aria-hidden="true"></span>
            ${escapeHtml(booking.status_label || 'Заявка отправлена')}
            <button type="button" class="fc-link" data-venue-booking-details data-booking-id="${escapeHtml(booking.booking_id)}" data-booking-status-url="${escapeHtml(booking.status_url)}" data-booking-details-url="${escapeHtml(booking.details_url)}">посмотреть</button>
            </span></span></div>`;
        activeCell = null;
    }

    function renderBookingDetails(data) {
        if (!detailsModal || !data) return;
        const content = detailsModal.querySelector('[data-venue-booking-details-content]');
        const start = new Date(data.starts_at);
        const end = new Date(data.ends_at);
        const payment = data.payment?.amount_minor == null ? 'Не требуется' : `${(data.payment.amount_minor / 100).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ${currencyLabel(data.payment.currency)}`;
        content.innerHTML = `<dl>
            <div><dt>Статус</dt><dd data-venue-booking-status>${escapeHtml(data.status_label || data.status)}</dd></div>
            <div><dt>Время</dt><dd>${start.toLocaleString('ru-RU')}–${end.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</dd></div>
            <div><dt>Зона</dt><dd>${escapeHtml(scopeLabel(data.scope))}</dd></div>
            <div><dt>Стоимость</dt><dd>${escapeHtml(payment)}</dd></div>
        </dl>`;
    }

    async function openBookingDetails(dataset, initial = null) {
        activeBooking = {
            id: dataset.bookingId,
            statusUrl: dataset.bookingStatusUrl,
            detailsUrl: dataset.bookingDetailsUrl,
        };
        detailsModal.querySelector('[data-venue-booking-details-page]').href = activeBooking.detailsUrl || '#';
        detailsModal.querySelector('[data-venue-booking-details-message]').textContent = '';
        if (initial?.starts_at) renderBookingDetails(initial);
        else detailsModal.querySelector('[data-venue-booking-details-content]').innerHTML = '<div class="venue-booking-details-modal__loading">Загружаем заявку…</div>';
        detailsTrigger?.click();
        if (activeBooking.statusUrl) await refreshBookingDetails();
    }

    async function refreshBookingDetails() {
        if (!activeBooking?.statusUrl) return;
        const button = detailsModal.querySelector('[data-venue-booking-refresh]');
        const message = detailsModal.querySelector('[data-venue-booking-details-message]');
        button.disabled = true;
        message.textContent = 'Обновляем…';
        try {
            const response = await fetch(activeBooking.statusUrl, { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(response.status === 429 ? 'Слишком много обновлений. Попробуйте через минуту.' : errorMessage(data, 'Не удалось обновить статус.'));
            renderBookingDetails(data);
            root.querySelectorAll(`[data-booking-id="${CSS.escape(activeBooking.id)}"]`).forEach((link) => {
                const status = link.closest('.venue-occupancy-slot__status');
                if (!status) return;
                status.className = `venue-occupancy-slot__status venue-occupancy-slot__status--${data.status}`;
                status.childNodes.forEach((node) => { if (node.nodeType === Node.TEXT_NODE) node.textContent = ` ${data.status_label} `; });
            });
            message.textContent = 'Статус обновлён.';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    }

    function openModalDay(index) {
        document.querySelector(`[data-venue-occupancy-day][data-day-index="${index}"]`)?.click();
    }

    function intent() {
        const date = root.dataset.bookingIntentDate;
        const start = root.dataset.bookingIntentStart;
        if (!date || !start) return;
        const url = new URL(window.location.href);
        const params = url.searchParams;
        const shouldResume = config.authenticated && params.get('booking_resume') === '1';
        const panel = root.querySelector(`[data-venue-occupancy-panel][data-day-date="${CSS.escape(date)}"]`);
        const cell = panel?.querySelector(`[data-venue-rental-cell][data-start="${CSS.escape(start)}"]`);
        if (!panel || !cell) return;
        openModalDay(panel.dataset.dayIndex);
        window.requestAnimationFrame(() => {
            openCell(cell);
            const form = cell.querySelector('[data-venue-rental-form]');
            if (params.get('booking_duration')) form.elements.duration_minutes.value = params.get('booking_duration');
            if (params.get('booking_scope')) form.elements.scope.value = params.get('booking_scope');
            updatePrice(form);

            if (!shouldResume) return;

            ['booking_resume', 'booking_date', 'booking_start', 'booking_duration', 'booking_scope'].forEach((key) => {
                url.searchParams.delete(key);
            });
            window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
            form.requestSubmit();
        });
    }

    detailsModal?.querySelector('[data-venue-booking-refresh]')?.addEventListener('click', refreshBookingDetails);
    window.requestAnimationFrame(intent);
}

function scopeLabel(scope) {
    return { whole: 'Весь зал', half_a: 'Половина A', half_b: 'Половина B' }[scope] || scope || 'Не указана';
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
