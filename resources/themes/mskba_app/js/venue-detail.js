/* Venue gallery, accessible local dialogs and attendance controls.
 * The gallery is static before hydration and respects reduced motion. */
document.querySelectorAll('[data-venue-gallery]').forEach(gallery => {
    const slides = [...gallery.querySelectorAll('[data-gallery-slide]')];
    const thumbs = [...gallery.querySelectorAll('[data-gallery-thumb]')];
    if (slides.length < 2) return;
    let active = 0;
    let interval = null;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    function show(index) {
        active = (index + slides.length) % slides.length;
        slides.forEach((img, i) => {
            img.classList.toggle('is-active', i === active);
            img.setAttribute('aria-hidden', String(i !== active));
        });
        thumbs.forEach((btn, i) => {
            btn.classList.toggle('is-active', i === active);
            btn.setAttribute('aria-pressed', String(i === active));
        });
    }
    function stop() { if (interval) clearInterval(interval); interval = null; }
    function start() {
        stop();
        if (!reduced.matches && !document.hidden) interval = setInterval(() => show(active + 1), 5500);
    }
    thumbs.forEach(btn => btn.addEventListener('click', () => {
        show(Number(btn.dataset.galleryThumb)); start();
    }));
    gallery.addEventListener('mouseenter', stop);
    gallery.addEventListener('mouseleave', start);
    gallery.addEventListener('focusin', stop);
    gallery.addEventListener('focusout', event => {
        if (!gallery.contains(event.relatedTarget)) start();
    });
    document.addEventListener('visibilitychange', start);
    reduced.addEventListener('change', start);
    start();
});

// Follow scroll position and keep hash targets visible below the sticky bars.
const sectionNav = document.querySelector('[data-venue-section-nav]');
if (sectionNav) {
    const links = [...sectionNav.querySelectorAll('a[href^="#"]')];
    const sections = links.map(link => document.getElementById(link.hash.slice(1)));
    let scheduled = false;

    // The navigation itself is the source of truth: unlike header heights,
    // its bottom edge follows both sticky layers during hide/reveal animation.
    function navOffset() {
        return Math.max(0, sectionNav.getBoundingClientRect().bottom);
    }

    function refreshNavHeight() {
        document.documentElement.style.setProperty(
            '--app-venue-nav-height', Math.ceil(sectionNav.getBoundingClientRect().height) + 'px',
        );
        scheduleActiveUpdate();
    }

    function updateActiveSection() {
        scheduled = false;
        const threshold = navOffset() + 16;
        let active = sections[0];
        for (const section of sections) {
            if (section && section.getBoundingClientRect().top <= threshold) active = section;
            else break;
        }
        links.forEach((link, index) => {
            if (sections[index] === active) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
        const activeLink = links[sections.indexOf(active)];
        if (activeLink && sectionNav.scrollWidth > sectionNav.clientWidth) {
            const left = activeLink.offsetLeft;
            const right = left + activeLink.offsetWidth;
            if (left < sectionNav.scrollLeft || right > sectionNav.scrollLeft + sectionNav.clientWidth) {
                sectionNav.scrollTo({ left: Math.max(0, left - 12), behavior: 'auto' });
            }
        }
    }

    function scheduleActiveUpdate() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(updateActiveSection);
    }

    links.forEach(link => link.addEventListener('click', () => {
        links.forEach(other => other.removeAttribute('aria-current'));
        link.setAttribute('aria-current', 'location');
    }));
    window.addEventListener('scroll', scheduleActiveUpdate, { passive: true });
    window.addEventListener('resize', refreshNavHeight, { passive: true });
    window.addEventListener('hashchange', scheduleActiveUpdate);
    let activeTransitionFrame = null;
    window.addEventListener('mskba:header-visibility', () => {
        const deadline = performance.now() + 340;
        if (activeTransitionFrame !== null) cancelAnimationFrame(activeTransitionFrame);
        const followNavigation = () => {
            scheduleActiveUpdate();
            activeTransitionFrame = performance.now() < deadline
                ? requestAnimationFrame(followNavigation)
                : null;
        };
        activeTransitionFrame = requestAnimationFrame(followNavigation);
    });
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(refreshNavHeight).observe(sectionNav);
    refreshNavHeight();
}

// All venue maps, including PageBuilder catalog cards, are handled by
// the shared venue-map-dialog.js module imported by app.js.
const playersDialog = document.querySelector('[data-venue-interests-dialog]');
const playersList = playersDialog || document.querySelector('[data-venue-interest-group-users]');
const playerDetailsDialog = document.querySelector('[data-venue-player-details-dialog]');
const slotDialog = document.querySelector('[data-venue-slot-dialog]');
const createEventDialog = document.querySelector('[data-venue-create-event-dialog]');
for (const dialog of [playersDialog, playerDetailsDialog, slotDialog, createEventDialog]) {
    if (!dialog) continue;
    dialog.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
}
document.querySelector('[data-venue-interests-open]')?.addEventListener('click', () => playersDialog?.showModal());
playersList?.addEventListener('click', event => {
    const button = event.target.closest?.('[data-venue-player-details-open]');
    if (!button || !playerDetailsDialog) return;
    const template = button.closest('.app-venue-interests-dialog__player')
        ?.querySelector('template[data-venue-player-template]');
    const content = playerDetailsDialog.querySelector('[data-venue-player-details-body]');
    if (!template || !content) return;
    content.replaceChildren(template.content.cloneNode(true));
    playerDetailsDialog.showModal();
});
playerDetailsDialog?.addEventListener('close', () => {
    playerDetailsDialog.querySelector('[data-venue-player-details-body]')?.replaceChildren();
});
document.querySelector('[data-venue-create-event-open]')?.addEventListener('click', () => createEventDialog?.showModal());

// Occupied time is always clickable, but private bookings reveal no event
// details. Joining is offered only when the server marks a public event open.
document.querySelectorAll('[data-venue-slot-open]').forEach(button => {
    button.addEventListener('click', () => {
        if (!slotDialog) return;
        const eventUrl = button.dataset.eventUrl || '';
        const joinUrl = button.dataset.eventJoinUrl || '';
        const joinState = button.dataset.joinState || 'unavailable';
        const canJoin = button.dataset.canJoin === 'true' && !!eventUrl && !!joinUrl;
        const canRespondTentative = button.dataset.canRespondTentative === 'true' && !!eventUrl && !!joinUrl;
        const eventLink = slotDialog.querySelector('[data-venue-slot-event-link]');
        const joinForm = slotDialog.querySelector('[data-venue-slot-join-form]');
        const tentativeForm = slotDialog.querySelector('[data-venue-slot-tentative-form]');
        const guestLink = slotDialog.querySelector('[data-venue-slot-guest-link]');
        const hasEvent = !!eventUrl;

        slotDialog.querySelector('[data-venue-slot-title]').textContent =
            hasEvent ? (button.dataset.eventTitle || button.dataset.eventType || 'Мероприятие') : 'Занятое время';
        slotDialog.querySelector('[data-venue-slot-time]').textContent =
            [button.dataset.slotDay, button.dataset.slotTime].filter(Boolean).join(' · ');
        const messages = {
            unavailable: 'Детали этой брони не опубликованы. Присоединение недоступно.',
            ended: 'Мероприятие уже завершилось. Изменить участие нельзя.',
            organizer: 'Ты организатор этого мероприятия. Управлять им можно на странице мероприятия.',
            joined: 'Ты уже участвуешь в мероприятии.',
            full: 'Все места заняты. Можно ответить «Думаю», но это не резервирует место.',
            reconfirm: 'Условия мероприятия изменились. Нужно подтвердить участие повторно.',
            open: 'Набор участников открыт.',
        };
        slotDialog.querySelector('[data-venue-slot-message]').textContent =
            joinState === 'full' && !tentativeForm
                ? 'Все места заняты. После входа можно ответить «Думаю», но это не резервирует место.'
                : (messages[joinState] || messages.unavailable);

        if (eventLink) {
            eventLink.hidden = !hasEvent;
            if (hasEvent) eventLink.href = eventUrl;
            else eventLink.removeAttribute('href');
        }
        if (joinForm) {
            joinForm.hidden = !canJoin;
            if (canJoin) joinForm.action = joinUrl;
            else joinForm.removeAttribute('action');
            const methodField = joinForm.querySelector('[data-venue-slot-join-method]');
            if (methodField) methodField.value = button.dataset.eventJoinMethod === 'POST'
                ? 'POST' : 'PATCH';
            const joinLabel = joinForm.querySelector('[data-venue-slot-join-label]');
            if (joinLabel) joinLabel.textContent = joinState === 'reconfirm'
                ? 'Подтвердить участие' : 'Присоединиться';
        }
        if (tentativeForm) {
            tentativeForm.hidden = !canRespondTentative;
            if (canRespondTentative) tentativeForm.action = joinUrl;
            else tentativeForm.removeAttribute('action');
        }
        if (guestLink) {
            // The public event page cannot capture guest "Думаю" responses
            // for a full event, so avoid an action that leads to a dead end.
            guestLink.hidden = !canJoin;
            if (canJoin) {
                guestLink.href = eventUrl;
                guestLink.textContent = 'Присоединиться';
            } else guestLink.removeAttribute('href');
        }
        slotDialog.showModal();
    });
});

// Follow the actual sticky navigation rather than a hard-coded height:
// navigation can wrap and theme/header dimensions can change independently.
const venueSidebar = document.querySelector('.app-venue-page__aside');
const venueAnchors = document.querySelector('.app-venue-page__anchors');
if (venueSidebar && venueAnchors) {
    let pending = false;
    const updateSidebarPosition = () => {
        pending = false;
        if (!venueSidebar.getClientRects().length) return;
        const navBottom = venueAnchors.getBoundingClientRect().bottom;
        const contextBottom = document.querySelector('.app-context-bar')?.getBoundingClientRect().bottom || 0;
        const headerBottom = document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0;
        const fixedHeaderBottom = Math.max(contextBottom, headerBottom, 0);
        const top = Math.ceil(Math.max(navBottom, fixedHeaderBottom) + 15);
        venueSidebar.style.setProperty('--venue-sidebar-sticky-top', top + 'px');
        venueAnchors.closest('.app-venue-page')?.style.setProperty('--app-venue-nav-height', venueAnchors.offsetHeight + 'px');
        venueAnchors.classList.toggle('is-stuck',
            Math.abs(venueAnchors.getBoundingClientRect().top - fixedHeaderBottom) <= 2
            && window.scrollY > 0);
    };
    const scheduleSidebarUpdate = () => {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(updateSidebarPosition);
    };
    // Header and breadcrumbs animate even without a scroll event (top-edge hover).
    // Re-read their real bottom edge during the 280ms transition so the venue
    // sidebar never crosses the horizontal anchors while either bar is moving.
    let headerAnimationFrame = null;
    window.addEventListener('mskba:header-visibility', () => {
        const deadline = performance.now() + 340;
        if (headerAnimationFrame !== null) cancelAnimationFrame(headerAnimationFrame);

        const followHeader = () => {
            scheduleSidebarUpdate();
            headerAnimationFrame = performance.now() < deadline
                ? requestAnimationFrame(followHeader)
                : null;
        };
        headerAnimationFrame = requestAnimationFrame(followHeader);
    });
    window.addEventListener('scroll', scheduleSidebarUpdate, { passive: true });
    window.addEventListener('resize', scheduleSidebarUpdate);
    if ('ResizeObserver' in window) new ResizeObserver(scheduleSidebarUpdate).observe(venueAnchors);
    scheduleSidebarUpdate();
}

// A guest opening the subscription wizard from the audience modal should
// close the audience first. Subscribed users navigate directly to their account.
document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-venue-interest-action]');
    if (button && playersDialog?.open) playersDialog.close();
}, true);


// Read-only daily availability with a contiguous selection that proceeds
// through MSKBA's existing quote/request flow. A server-wide five-minute
// FindSport cooldown is enforced by the API, never by this browser code.
const dayBookingDialog = document.querySelector('[data-venue-day-dialog]');
if (dayBookingDialog) {
    const status = dayBookingDialog.querySelector('[data-venue-day-status]');
    const heading = dayBookingDialog.querySelector('[data-venue-day-title]');
    const grid = dayBookingDialog.querySelector('[data-venue-day-slots]');
    const form = dayBookingDialog.querySelector('[data-venue-day-booking-form]');
    const bookingButton = dayBookingDialog.querySelector('[data-venue-day-book]');
    const summary = dayBookingDialog.querySelector('[data-venue-day-summary]');
    const minimum = dayBookingDialog.querySelector('[data-venue-day-minimum]');
    const dateButtons = Array.from(dayBookingDialog.querySelectorAll('[data-venue-day-date]'));
    const dateValues = dateButtons.map(button => button.dataset.venueDayDate);
    const dateNav = Array.from(dayBookingDialog.querySelectorAll('[data-venue-day-nav]'));
    let activeDate = null;
    const currency = new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2});
    let response = null;
    let selection = [];
    let loading = null;
    let previewSequence = 0;

    const selectedIndexesAreContiguous = () => selection.every((index, i) =>
        i === 0 || index === selection[i - 1] + 1);
    function redrawSelection() {
        const step = response?.step_minutes ?? 30;
        const required = response?.minimum_minutes ?? 60;
        const duration = selection.length * step;
        const enough = duration >= required
            && duration <= (response?.maximum_minutes ?? 0)
            && selectedIndexesAreContiguous();
        const canBook = !!response?.booking_enabled && enough;
        bookingButton.disabled = !canBook;
        grid.querySelectorAll('button[data-slot-index]').forEach(button => {
            const active = selection.includes(Number(button.dataset.slotIndex));
            button.classList.toggle('is-selected', active);
            button.setAttribute('aria-pressed', String(active));
        });
        if (selection.length === 0) {
            summary.textContent = 'Выберите свободные интервалы';
        } else {
            const start = response.slots[selection[0]];
            const end = response.slots[selection.at(-1)];
            summary.textContent = [start.starts_at, '–', end.ends_at, '·',
                duration, 'мин ·', canBook ? 'Рассчитываем стоимость…' : 'Выберите доступное время'].join(' ');
        }
        minimum.textContent = response?.booking_enabled
            ? 'Минимум ' + required + ' мин · непрерывные слоты'
            : 'Бронирование пока не включено для этого зала';
        if (canBook) {
            form.querySelector('[data-venue-day-court]').value = String(response.venue_court_id);
            form.querySelector('[data-venue-day-start]').value =
                response.date + 'T' + response.slots[selection[0]].starts_at;
            form.querySelector('[data-venue-day-duration]').value = String(duration);
            bookingButton.disabled = true;
            // The previous 'start' variable belonged to the summary's else block.
            // Use the selected slot from the current response; otherwise the
            // ReferenceError leaves the booking button disabled indefinitely.
            void previewSelection(
                response.date, response.slots[selection[0]].starts_at, duration, selection.join(','),
            );
        } else {
            ++previewSequence;
        }
    }

    // The selected range is priced as one rental: fixed commissions are charged
    // once, and duration-dependent tariff rules apply to the whole range.
    async function previewSelection(date, startsAt, duration, selectedKey) {
        const sequence = ++previewSequence;
        try {
            const url = new URL(dayBookingDialog.dataset.dayUrl, window.location.href);
            url.searchParams.set('date', date);
            url.searchParams.set('venue_court_id', String(response.venue_court_id));
            url.searchParams.set('preview_start', startsAt);
            url.searchParams.set('preview_duration_minutes', String(duration));
            const result = await fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
            });
            const data = await result.json();
            if (!result.ok || !data.price_preview) {
                throw new Error(data.message || 'Не удалось рассчитать стоимость');
            }
            if (sequence !== previewSequence || !dayBookingDialog.open
                || selection.join(',') !== selectedKey) return;
            summary.textContent = [startsAt, '–', response.slots[selection.at(-1)].ends_at, '·',
                duration, 'мин ·', currency.format(data.price_preview.total_minor / 100), '₽'].join(' ');
            bookingButton.disabled = false;
        } catch (error) {
            if (sequence !== previewSequence || !dayBookingDialog.open) return;
            summary.textContent = error.message || 'Стоимость недоступна';
            bookingButton.disabled = true;
        }
    }

    // Update the clicked card after its on-demand five-minute refresh.
    function updateDayOccupancy(date, slots) {
        const card = document.querySelector(
            '[data-venue-day-entry][data-day-date="' + date + '"]',
        );
        const track = card?.querySelector('[data-day-occupancy]');
        const booked = card?.querySelector('[data-day-occupancy-booked]');
        if (!track || !booked) return;

        const known = slots.filter(slot =>
            slot.status === 'available' || slot.status === 'booked');
        const percentage = known.length
            ? Math.round(100 * known.filter(slot => slot.status === 'booked').length / known.length)
            : null;
        track.classList.toggle('is-unknown', percentage === null);
        const label = percentage === null
            ? 'Нет данных о занятости' : 'Занято ' + percentage + '%';
        track.setAttribute('aria-label', label);
        track.title = label;
        booked.style.width = (percentage ?? 0) + '%';
    }

    function renderSlots(data) {
        response = data;
        selection = [];
        grid.replaceChildren();
        const labels = {
            available: 'Свободно',
            booked: 'Занято',
            unavailable: 'Недоступно',
            unknown: 'Нет данных',
        };
        for (const [index, slot] of data.slots.entries()) {
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'app-venue-day-dialog__slot';
            cell.dataset.slotIndex = String(index);
            cell.dataset.slotStatus = slot.status;
            cell.disabled = !slot.selectable;
            cell.setAttribute('aria-pressed', 'false');
            const times = document.createElement('strong');
            times.textContent = slot.starts_at + '–' + slot.ends_at;
            const state = document.createElement('span');
            state.textContent = labels[slot.status] || 'Неизвестно';
            const price = document.createElement('small');
            price.textContent = slot.price_minor === null
                ? 'Цена не указана'
                : currency.format(slot.price_minor / 100) + ' ₽';
            if (slot.price_minor !== null) {
                price.title = 'Цена одного интервала с комиссиями. Итог для нескольких интервалов рассчитывается отдельно.';
            }
            cell.append(times, state, price);
            grid.appendChild(cell);
        }
        // Keep the update timestamp available on hover/focus, without
        // taking space from the future day-info / promotion area.
        heading.title = data.last_updated_at
            ? 'up: ' + data.last_updated_at.slice(0, 16) + ' (UTC +3)'
            : '';
        const warning = data.refresh_state === 'failed' ? data.refresh_message : null;
        status.textContent = warning || '';
        status.hidden = !warning;
        updateDayOccupancy(data.date, data.slots);
        const selectedDateButton = dateButtons.find(button => button.dataset.venueDayDate === data.date);
        const hours = selectedDateButton?.querySelector('.app-venue-day-dialog__date-hours');
        if (hours && data.slots.length) {
            const last = data.slots.at(-1).ends_at;
            hours.textContent = data.slots[0].starts_at + '–' + (last === '24:00' ? '00:00' : last);
        }
        redrawSelection();
    }

    grid.addEventListener('click', event => {
        const button = event.target.closest('button[data-slot-index]');
        if (!button || button.disabled || !response) return;
        const index = Number(button.dataset.slotIndex);
        if (selection.includes(index)) {
            if (selection.length === 1) selection = [];
            else if (index === selection[0]) selection.shift();
            else if (index === selection.at(-1)) selection.pop();
            else selection = [index];
        } else if (selection.length && index === selection[0] - 1) {
            selection.unshift(index);
        } else if (selection.length && index === selection.at(-1) + 1) {
            selection.push(index);
        } else {
            selection = [index];
        }
        if (selection.length * response.step_minutes > response.maximum_minutes) {
            selection = [index];
        }
        redrawSelection();
    });

    const close = () => {
        ++previewSequence;
        loading?.abort();
        loading = null;
        if (dayBookingDialog.open) dayBookingDialog.close();
    };
    dayBookingDialog.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', close));
    dayBookingDialog.addEventListener('click', event => {
        if (event.target === dayBookingDialog) close();
    });
    dayBookingDialog.addEventListener('close', () => {
        loading?.abort();
        loading = null;
        response = null;
        selection = [];
        ++previewSequence;
        bookingButton.disabled = true;
    });

    function setActiveDate(date) {
        activeDate = date;
        const index = dateValues.indexOf(date);
        dateButtons.forEach(button => {
            const selected = button.dataset.venueDayDate === date;
            button.setAttribute('aria-pressed', String(selected));
            if (selected) button.scrollIntoView?.({block: 'nearest', inline: 'nearest'});
        });
        dateNav.forEach(button => {
            const offset = Number(button.dataset.venueDayNav);
            button.disabled = index < 0 || index + offset < 0 || index + offset >= dateValues.length;
        });
    }

    async function showDay(date) {
        if (!date) return;
        ++previewSequence;
        loading?.abort();
        loading = new AbortController();
        const signal = loading.signal;
        response = null;
        selection = [];
        bookingButton.disabled = true;
        grid.replaceChildren();
        heading.removeAttribute('title');
        setActiveDate(date);
        const label = dateButtons.find(button => button.dataset.venueDayDate === date)
            ?.querySelector('strong')?.textContent || date;
        heading.textContent = 'Расписание · ' + label;
        status.hidden = false;
        status.textContent = 'Проверяем расписание…';
        if (!dayBookingDialog.open) dayBookingDialog.showModal();
        try {
            const url = new URL(dayBookingDialog.dataset.dayUrl, window.location.href);
            url.searchParams.set('date', date);
            if (dayBookingDialog.dataset.courtId) {
                url.searchParams.set('venue_court_id', dayBookingDialog.dataset.courtId);
            }
            const result = await fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                signal,
            });
            const data = await result.json();
            if (!result.ok) throw new Error(data.message || 'Не удалось загрузить расписание');
            if (signal.aborted || !dayBookingDialog.open) return;
            renderSlots(data);
        } catch (error) {
            if (signal.aborted || !dayBookingDialog.open) return;
            status.textContent = error.message || 'Не удалось получить расписание.';
            status.hidden = false;
            bookingButton.disabled = true;
        }
    }

    document.querySelectorAll('[data-venue-day-open]').forEach(button => {
        button.addEventListener('click', () => showDay(button.dataset.dayDate));
    });
    dateButtons.forEach(button => {
        button.addEventListener('click', () => void showDay(button.dataset.venueDayDate));
    });
    dateNav.forEach(button => {
        button.addEventListener('click', () => {
            const nextIndex = dateValues.indexOf(activeDate) + Number(button.dataset.venueDayNav);
            if (nextIndex >= 0 && nextIndex < dateValues.length) void showDay(dateValues[nextIndex]);
        });
    });
    // The review page opens the same dialog after a court change. The
    // original priced quote is not replaced until the visitor confirms slots.
    dayBookingDialog.addEventListener('mskba:choose-court', event => {
        const nextCourt = Number(event.detail?.courtId);
        if (Number.isInteger(nextCourt) && nextCourt > 0) {
            dayBookingDialog.dataset.courtId = String(nextCourt);
        }
        const preferredDate = event.detail?.date;
        void showDay(dateValues.includes(preferredDate) ? preferredDate
            : (dateValues.includes(activeDate) ? activeDate : dateValues[0]));
    });
}
