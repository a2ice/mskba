import { displayClock } from './venue-time-label.js';
import { groupDayBookingSlots } from './venue-day-booking-groups.js';

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
        const hasEvent = button.dataset.hasEvent === 'true' || !!eventUrl;

        slotDialog.querySelector('[data-venue-slot-title]').textContent =
            hasEvent ? (eventUrl ? (button.dataset.eventTitle || button.dataset.eventType || 'Мероприятие') : 'Мероприятие по приглашению') : 'Занятое время';
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
    const eventInfoDialog = document.querySelector('[data-venue-day-event-dialog]');
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

    // Keep only the selected public schedule coordinates across an auth
    // redirect. Owner permissions are always re-fetched from the server.
    const bookingResumeKey = 'mskba:venue-booking-popup-resume';
    const readBookingResume = () => {
        try {
            const raw = window.sessionStorage?.getItem(bookingResumeKey);
            if (!raw) return null;
            const saved = JSON.parse(raw);
            if (Date.now() - Number(saved.savedAt) > 10 * 60 * 1000
                || saved.path !== window.location.pathname) {
                window.sessionStorage.removeItem(bookingResumeKey);
                return null;
            }
            return saved;
        } catch { return null; }
    };
    const saveBookingResume = (slot) => {
        try {
            window.sessionStorage?.setItem(bookingResumeKey, JSON.stringify({
                path: window.location.pathname, date: activeDate,
                courtId: dayBookingDialog.dataset.courtId || null,
                startsAt: slot.starts_at,
                bookingKey: slot.booking_group?.key || null,
                savedAt: Date.now(),
            }));
        } catch { /* Session storage is optional. */ }
    };
    window.addEventListener?.('mskba:auth:success', () => {
        if (readBookingResume()) window.location.reload();
    });

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
            summary.textContent = [displayClock(start.starts_at), '–', displayClock(end.ends_at), '·',
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
            summary.textContent = [displayClock(startsAt), '–', displayClock(response.slots[selection.at(-1)].ends_at), '·',
                duration, 'мин ·', currency.format(data.price_preview.total_minor / 100), '₽'].join(' ');
            if (data.price_preview.personal_discount) {
                const original = document.createElement('del');
                original.textContent = currency.format(data.price_preview.personal_discount.original_amount_minor / 100) + ' ₽';
                const reason = document.createElement('span');
                reason.textContent = ' Специальные условия';
                reason.title = data.price_preview.personal_discount.reason;
                summary.prepend(original, document.createTextNode(' '));
                summary.append(reason);
            }
            bookingButton.disabled = false;
        } catch (error) {
            if (sequence !== previewSequence || !dayBookingDialog.open) return;
            summary.textContent = error.message || 'Стоимость недоступна';
            bookingButton.disabled = true;
        }
    }

    // Update the clicked card after its on-demand five-minute refresh.
    function updateDayOccupancy(date, slots) {
        const cards = [
            document.querySelector('[data-venue-schedule-court="' + dayBookingDialog.dataset.courtId
                + '"] [data-venue-day-entry][data-day-date="' + date + '"]'),
            dateButtons.find(button => button.dataset.venueDayDate === date)?.parentElement,
        ];
        const known = slots.filter(slot =>
            slot.status === 'available' || slot.status === 'booked');
        const percentage = known.length
            ? Math.round(100 * known.filter(slot => slot.status === 'booked').length / known.length)
            : null;
        for (const card of cards) {
            const track = card?.querySelector('[data-day-occupancy]');
            const booked = card?.querySelector('[data-day-occupancy-booked]');
            if (!track || !booked) continue;
            track.classList.toggle('is-unknown', percentage === null);
            const label = percentage === null
                ? 'Нет данных о занятости' : 'Занято ' + percentage + '%';
            track.setAttribute('aria-label', label);
            track.title = label;
            booked.style.width = (percentage ?? 0) + '%';
        }
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
        for (const group of groupDayBookingSlots(data.slots)) {
            const slot = group.first;
            const eventInfo = slot.booking_group?.event || null;
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'app-venue-day-dialog__slot';
            if (slot.selectable) cell.dataset.slotIndex = String(group.firstIndex);
            if (group.count > 1) cell.classList.add('is-booking-group');
            if (eventInfo) cell.classList.add('has-attached-event');
            if (slot.status === 'booked') cell.classList.add('has-booking-details');
            cell.dataset.bookingStartsAt = slot.starts_at;
            if (slot.booking_group?.key) cell.dataset.bookingKey = slot.booking_group.key;
            if (eventInfo?.public) cell.classList.add('is-public-event');
            cell.dataset.slotStatus = slot.status;
            // Occupied slots are informational, even when there is no event.
            // Only available slots remain selectable for a new reservation.
            cell.disabled = !slot.selectable && slot.status !== 'booked';
            cell.setAttribute('aria-pressed', 'false');
            const times = document.createElement('strong');
            times.textContent = displayClock(slot.starts_at) + '–' + displayClock(group.last.ends_at);
            const state = document.createElement('span');
            const marker = slot.booking_group
                ? ' (m' + (slot.booking_group.has_event ? ', e' : '') + ')' : '';
            state.textContent = (labels[slot.status] || 'Неизвестно') + marker;
            const price = document.createElement('small');
            price.textContent = group.count > 1
                ? (group.count * data.step_minutes) + ' мин'
                : slot.price_minor === null
                    ? 'Цена не указана'
                    : currency.format(slot.price_minor / 100) + ' ₽';
            if (group.count === 1 && slot.price_minor !== null) {
                price.title = 'Цена одного интервала с комиссиями. Итог для нескольких интервалов рассчитывается отдельно.';
            }
            if (slot.status === 'booked') {
                const hasEvent = !!eventInfo;
                const isPublic = eventInfo?.public === true;
                const eventLabel = !hasEvent ? 'Сведения о занятости'
                    : (isPublic ? (eventInfo.title || eventInfo.type || 'Мероприятие')
                        : 'Мероприятие по приглашению');
                cell.setAttribute('aria-label',
                    times.textContent + ' · ' + state.textContent + ' · ' + eventLabel + '. Открыть сведения');
                cell.addEventListener('click', () => {
                    if (!eventInfoDialog) return;
                    const info = (selector) => eventInfoDialog.querySelector(selector);
                    info('[data-venue-day-event-title]').textContent =
                        !hasEvent ? 'Занятое время'
                            : (isPublic ? (eventInfo.title || 'Мероприятие') : 'Мероприятие');
                    info('[data-venue-day-event-time]').textContent = times.textContent;
                    info('[data-venue-day-event-access]').textContent = hasEvent
                        ? (eventInfo.visibility || 'Подробности не опубликованы')
                        : (slot.booking_group ? 'Бронирование MSKBA' : 'Занято по расписанию площадки');
                    info('[data-venue-day-event-type]').textContent = hasEvent
                        ? (eventInfo.type || 'Не указан')
                        : (slot.booking_group ? 'Бронирование' : 'Занятый интервал');

                    const description = info('[data-venue-day-event-description]');
                    description.textContent = isPublic ? (eventInfo.description || '') : '';
                    description.hidden = !isPublic || !eventInfo?.description;
                    const note = info('[data-venue-day-event-private-note]');
                    // Booking-only slots have their own permission-based
                    // actions below; do not repeat that no event is attached.
                    note.hidden = !hasEvent || isPublic;
                    note.textContent = 'Подробности доступны только приглашённым участникам.';
                    const bookingActions = info('[data-venue-day-booking-actions]');
                    const bookingStatus = info('[data-venue-day-booking-action-status]');
                    const createEvent = info('[data-venue-day-booking-create-event]');
                    const loginButton = info('[data-venue-day-booking-login]');
                    const requestUrl = slot.booking_group?.request_url || '';
                    const requesterLogin = slot.booking_group?.requester_login || '';
                    const requesterIsViewer = !!slot.booking_group?.requester_is_viewer;
                    const requestLink = info('[data-venue-day-booking-request-link]');
                    requestLink.hidden = !requestUrl;
                    if (requestUrl) requestLink.href = requestUrl;
                    else requestLink.removeAttribute('href');
                    const requesterRow = info('[data-venue-day-booking-requester-row]');
                    requesterRow.hidden = !requestUrl || !requesterLogin;
                    info('[data-venue-day-booking-requester]').textContent =
                        requesterRow.hidden ? '' : requesterLogin;
                    info('[data-venue-day-booking-self]').hidden =
                        requesterRow.hidden || !requesterIsViewer;
                    const createUrl = !hasEvent ? slot.booking_group?.create_event_url : null;
                    bookingActions.hidden = hasEvent || (!!requestUrl && !createUrl);
                    createEvent.hidden = !createUrl;
                    if (createUrl) createEvent.href = createUrl;
                    else createEvent.removeAttribute('href');
                    const guestBooking = !hasEvent && !!slot.booking_group
                        && data.viewer_authenticated === false;
                    if (loginButton) loginButton.hidden = !guestBooking;
                    if (!hasEvent) {
                        bookingStatus.textContent = createUrl
                            ? 'Вы можете создать мероприятие на основе этой брони.'
                            : (guestBooking
                                ? 'Для просмотра доступных действий авторизуйтесь.'
                                : 'Действия недоступны.');
                        if (guestBooking && loginButton) {
                            loginButton.onclick = () => {
                                saveBookingResume(slot);
                                eventInfoDialog.close();
                                window.dispatchEvent(new CustomEvent('mskba:auth:open', {
                                    detail: { mode: 'login', stayOnPage: true },
                                }));
                            };
                        }
                    }

                    info('[data-venue-day-event-status-row]').hidden = !isPublic;
                    info('[data-venue-day-event-status]').textContent =
                        isPublic ? (eventInfo.status || 'Не указан') : '';
                    info('[data-venue-day-event-count-row]').hidden = !isPublic;
                    info('[data-venue-day-event-count]').textContent = isPublic
                        ? String(eventInfo.confirmed_count ?? 0)
                            + (eventInfo.max_participants ? ' из ' + eventInfo.max_participants : '')
                            + ' · думают: ' + String(eventInfo.tentative_count ?? 0)
                        : '';
                    info('[data-venue-day-event-organizer-row]').hidden = !isPublic;
                    const organizer = info('[data-venue-day-event-organizer]');
                    organizer.textContent = isPublic ? (eventInfo.organizer_name || 'Организатор') : '';
                    const message = info('[data-venue-day-event-message]');
                    const canMessage = isPublic && !!eventInfo.organizer_user_id
                        && !!document.querySelector('[data-direct-message-dialog]');
                    message.hidden = !canMessage;
                    const organizerFallback = info('[data-venue-day-event-organizer-fallback]');
                    organizerFallback.hidden = canMessage || !isPublic;
                    organizerFallback.textContent =
                        isPublic ? (eventInfo.organizer_name || 'Организатор') : '';
                    if (canMessage) {
                        message.dataset.recipientId = String(eventInfo.organizer_user_id);
                        message.dataset.recipientName = eventInfo.organizer_name || 'Организатор';
                    } else {
                        delete message.dataset.recipientId;
                        delete message.dataset.recipientName;
                    }
                    const link = info('[data-venue-day-event-link]');
                    link.hidden = !isPublic || !eventInfo.url;
                    if (!link.hidden) link.href = eventInfo.url;
                    else link.removeAttribute('href');

                    // All join/tentative actions use existing, server-checked
                    // participation endpoints; never grant access based on UI.
                    const participation = isPublic ? eventInfo.participation : null;
                    const actionBox = info('[data-venue-day-event-participation]');
                    actionBox.hidden = !participation;
                    const joinState = participation?.state || 'unavailable';
                    const messages = {
                        unavailable: 'Запись на мероприятие недоступна.',
                        ended: 'Мероприятие уже завершено или набор закрыт.',
                        organizer: 'Вы организатор. Управление доступно на странице мероприятия.',
                        joined: 'Вы уже участвуете в мероприятии.',
                        full: 'Все места заняты. Можно ответить «Думаю», но это не бронирует место.',
                        reconfirm: 'Условия изменились. Требуется повторное подтверждение участия.',
                        open: 'Набор участников открыт.',
                    };
                    const guestCannotRespondTentative = joinState === 'full'
                        && !info('[data-venue-day-event-tentative-form]');
                    info('[data-venue-day-event-join-state]').textContent = participation
                        ? (guestCannotRespondTentative
                            ? 'Все места заняты. После входа можно ответить «Думаю», но это не резервирует место.'
                            : (messages[joinState] || messages.unavailable)) : '';

                    const joinForm = info('[data-venue-day-event-join-form]');
                    const tentativeForm = info('[data-venue-day-event-tentative-form]');
                    const guestLink = info('[data-venue-day-event-guest-join]');
                    if (joinForm) {
                        joinForm.hidden = !participation?.can_join || !participation?.join_url;
                        if (!joinForm.hidden) joinForm.action = participation.join_url;
                        else joinForm.removeAttribute('action');
                        const method = info('[data-venue-day-event-join-method]');
                        method.value = participation?.join_method === 'POST' ? 'POST' : 'PATCH';
                        info('[data-venue-day-event-join-label]').textContent =
                            joinState === 'reconfirm' ? 'Подтвердить участие' : 'Присоединиться';
                    }
                    if (tentativeForm) {
                        tentativeForm.hidden = !participation?.can_tentative || !participation?.join_url;
                        if (!tentativeForm.hidden) tentativeForm.action = participation.join_url;
                        else tentativeForm.removeAttribute('action');
                    }
                    if (guestLink) {
                        guestLink.hidden = !participation?.can_join || !eventInfo.url;
                        if (!guestLink.hidden) guestLink.href = eventInfo.url;
                        else guestLink.removeAttribute('href');
                    }
                    eventInfoDialog.showModal();
                    // Focus the real close control rather than the informational
                    // heading; retain keyboard focus for interactive elements.
                    eventInfoDialog.querySelector('[data-venue-overlay-close]')
                        ?.focus?.({ preventScroll: true });
                });
            }
            cell.append(times, state, price);
            grid.appendChild(cell);
        }
        // Keep the update timestamp available on hover/focus, without
        // taking space from the future day-info / promotion area.
        heading.title = data.last_updated_at
            ? 'up: ' + data.last_updated_at.slice(0, 16) + ' (UTC +3)'
            : '';
        // An unavailable freshness check must never look like a clickable
        // free slot. Keep the reason visible instead of a silent disabled CTA.
        const warning = data.stale
            ? (data.refresh_message || 'Расписание устарело. Выбор времени временно недоступен.')
            : (data.refresh_state === 'failed' ? data.refresh_message : null);
        status.textContent = warning || '';
        status.hidden = !warning;
        updateDayOccupancy(data.date, data.slots);
        const selectedDateButton = dateButtons.find(button => button.dataset.venueDayDate === data.date);
        const hours = selectedDateButton?.querySelector('.app-venue-day-dialog__date-hours');
        if (hours && data.slots.length) {
            const last = data.slots.at(-1).ends_at;
            hours.textContent = displayClock(data.slots[0].starts_at) + '–' + displayClock(last);
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

    eventInfoDialog?.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', () => eventInfoDialog.close()));
    eventInfoDialog?.addEventListener('click', event => {
        if (event.target === eventInfoDialog) eventInfoDialog.close();
    });
    eventInfoDialog?.querySelector('[data-venue-day-event-message]')?.addEventListener('click', () => {
        if (eventInfoDialog.open) eventInfoDialog.close();
    });

    const close = () => {
        if (eventInfoDialog?.open) eventInfoDialog.close();
        ++previewSequence;
        loading?.abort();
        loading = null;
        if (dayBookingDialog.open) dayBookingDialog.close();
    };
    dayBookingDialog.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', close));
    let beganOnDayBackdrop = false;
    dayBookingDialog.addEventListener('pointerdown', event => {
        beganOnDayBackdrop = event.target === dayBookingDialog;
    });
    dayBookingDialog.addEventListener('pointerup', event => {
        if (beganOnDayBackdrop && event.target === dayBookingDialog) close();
        beganOnDayBackdrop = false;
    });
    dayBookingDialog.addEventListener('pointercancel', () => { beganOnDayBackdrop = false; });
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

    async function showDay(date, resumeBooking = null) {
        if (!date) return;
        // The venue overview may have many halls with different free slots.
        // Never silently book the first hall when no explicit selection exists.
        if (!dayBookingDialog.dataset.courtId) {
            window.dispatchEvent(new CustomEvent('mskba:venue-court-selection-required', {
                detail: {date},
            }));
            return;
        }
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
        heading.textContent = 'Расписание · '
            + (dayBookingDialog.dataset.courtName ? dayBookingDialog.dataset.courtName + ' · ' : '')
            + label;
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
            if (resumeBooking) {
                const match = Array.from(grid.children).find(cell =>
                    cell.dataset.bookingStartsAt === resumeBooking.startsAt
                    && (!resumeBooking.bookingKey
                        || cell.dataset.bookingKey === resumeBooking.bookingKey));
                match?.click?.();
            }
        } catch (error) {
            if (signal.aborted || !dayBookingDialog.open) return;
            status.textContent = error.message || 'Не удалось получить расписание.';
            status.hidden = false;
            bookingButton.disabled = true;
        }
    }

    document.querySelectorAll('[data-venue-day-open]').forEach(button => {
        button.addEventListener('click', () => {
            const courtId = Number(button.dataset.courtId);
            if (Number.isSafeInteger(courtId) && courtId > 0) {
                dayBookingDialog.dispatchEvent(new CustomEvent('mskba:choose-court', {
                    detail: {courtId, date: button.dataset.dayDate},
                }));
            } else {
                // Preserve the Promise for callers (including booking tests).
                return showDay(button.dataset.dayDate);
            }
        });
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
    // Restore the same booking popup after normal login, social login or
    // registration redirect; the fresh day request resolves new owner rights.
    // Populate the date strip for the selected court, rather than using
    // common venue hours or the primary court's percentage for every hall.
    function syncCourtDates(courtId) {
        const group = document.querySelector('[data-venue-schedule-court="' + courtId + '"]');
        if (group) dayBookingDialog.dataset.courtName = group.dataset.courtName || '';
        dateButtons.forEach(button => {
            const source = group?.querySelector('[data-venue-day-entry][data-day-date="'
                + button.dataset.venueDayDate + '"]');
            const hours = button.querySelector('.app-venue-day-dialog__date-hours');
            const item = button.closest?.('[data-day-date]');
            const target = item?.querySelector('[data-day-occupancy]');
            const sourceProgress = source?.querySelector('[data-day-occupancy]');
            const sourceBooked = sourceProgress?.querySelector('[data-day-occupancy-booked]');
            if (hours) hours.textContent = source
                ? (source.querySelector('.app-venue-schedule__hours span')?.textContent?.trim()
                    || 'Не работает') : 'Время уточняется';
            if (target) {
                const unknown = !sourceProgress || sourceProgress.classList.contains('is-unknown');
                const label = unknown ? 'Нет данных о занятости'
                    : (sourceProgress.getAttribute('aria-label') || 'Нет данных о занятости');
                target.classList.toggle('is-unknown', unknown);
                target.setAttribute('aria-label', label);
                target.title = label;
                const bar = target.querySelector('[data-day-occupancy-booked]');
                if (bar) bar.style.width = unknown ? '0%' : (sourceBooked?.style.width || '0%');
            }
        });
    }
    if (dayBookingDialog.dataset.courtId) syncCourtDates(dayBookingDialog.dataset.courtId);

    const pendingResume = readBookingResume();
    if (pendingResume && dateValues.includes(pendingResume.date)) {
        try { window.sessionStorage?.removeItem(bookingResumeKey); } catch { /* optional */ }
        if (pendingResume.courtId) {
            dayBookingDialog.dataset.courtId = pendingResume.courtId;
            syncCourtDates(pendingResume.courtId);
        }
        void showDay(pendingResume.date, pendingResume);
    }

    dayBookingDialog.addEventListener('mskba:choose-court', event => {
        const nextCourt = Number(event.detail?.courtId);
        if (Number.isSafeInteger(nextCourt) && nextCourt > 0) {
            if (String(nextCourt) !== dayBookingDialog.dataset.courtId) {
                // Invalidate every day cell before requesting this court's slots.
                loading?.abort();
                response = null;
                selection = [];
                bookingButton.disabled = true;
                grid.replaceChildren();
                dayBookingDialog.dataset.courtId = String(nextCourt);
                syncCourtDates(nextCourt);
            }
        }
        const preferredDate = event.detail?.date;
        void showDay(dateValues.includes(preferredDate) ? preferredDate
            : (dateValues.includes(activeDate) ? activeDate : dateValues[0]));
    });
}
