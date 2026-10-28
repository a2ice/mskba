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
