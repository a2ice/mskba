import { subscribePublic } from '../../../js/realtime.js';

const review = document.querySelector('[data-rental-quote-page]');

if (review) {
    const form = review.querySelector('[data-venue-rental-form]');
    const court = form?.querySelector('[data-rental-court]');
    const start = form?.querySelector('[data-rental-starts]');
    const duration = form?.querySelector('[data-rental-duration]');
    const scope = form?.querySelector('[data-rental-scope]');
    const calculate = form?.querySelector('[data-rental-submit]');
    const calendar = document.querySelector('[data-venue-day-dialog]');
    const timePicker = form?.querySelector('[data-rental-open-calendar]');
    const monitor = review.querySelector('[data-rental-quote-monitor]');
    const chosenCourt = review.dataset.rentalCurrentCourt || '';

    const syncFields = () => {
        if (!court || !duration || !scope || !start || !calculate) return;
        const option = court.selectedOptions[0];
        const min = Number(option?.dataset.min || 60);
        const max = Number(option?.dataset.max || 480);
        const step = Number(option?.dataset.step || 30);
        duration.min = String(min);
        duration.max = String(max);
        duration.step = String(step);
        start.step = String(step * 60);
        const value = Number(duration.value);
        if (!value || value < min || value > max || value % step !== 0) duration.value = String(min);
        scope.querySelectorAll('option').forEach(item => {
            const valid = item.dataset.scope === 'whole'
                ? option?.dataset.whole === '1'
                : option?.dataset.halves === '1';
            item.disabled = !valid;
            item.hidden = !valid;
        });
        if (scope.selectedOptions[0]?.disabled) {
            scope.value = Array.from(scope.options).find(item => !item.disabled)?.value || '';
        }
        calculate.disabled = !Array.from(scope.options).some(item => !item.disabled);
    };

    const openCalendar = (targetCourt = court?.value) => {
        if (!calendar) return;
        calendar.dispatchEvent(new CustomEvent('mskba:choose-court', {
            detail: {
                courtId: Number(targetCourt),
                date: start?.value?.slice(0, 10) || review.dataset.rentalOriginalDate,
            },
        }));
    };

    court?.addEventListener('change', () => {
        syncFields();
        openCalendar(court.value);
    });
    timePicker?.addEventListener('click', () => openCalendar(court?.value));

    // Leaving the picker without confirming a new quote never silently
    // changes the hall attached to the already calculated offer.
    calendar?.addEventListener('close', () => {
        if (court && chosenCourt && court.value !== chosenCourt) {
            court.value = chosenCourt;
            syncFields();
        }
    });

    syncFields();

    if (monitor) {
        const button = monitor.querySelector('[data-rental-send-button]');
        const sendForm = monitor.querySelector('[data-rental-send-form]');
        const message = monitor.querySelector('[data-rental-live-message]');
        const conflict = document.querySelector('[data-rental-conflict-dialog]');
        const conflictMessage = conflict?.querySelector('[data-rental-conflict-message]');
        const conflictChoose = conflict?.querySelector('[data-rental-conflict-choose]');
        const closeConflict = conflict?.querySelector('[data-rental-conflict-close]');
        const expiresAt = new Date(monitor.dataset.quoteExpiration);
        const quotedDate = review.dataset.rentalOriginalDate || null;
        const courtId = Number(chosenCourt);
        const venueId = Number(review.dataset.rentalVenueId);
        let state = 'checking';
        let requestSequence = 0;
        let stopped = false;

        const descriptions = {
            unavailable: 'К сожалению, выбранный интервал уже заняли. Выберите другое свободное время.',
            expired: 'Срок действия расчёта истёк. Получите новый расчёт для доступного времени.',
            unknown: 'Не удалось проверить актуальность бронирования. Повторите проверку.',
        };

        function updateState(next) {
            state = next;
            const unavailable = state === 'unavailable' || state === 'expired';
            const unknown = state === 'unknown';
            if (message) {
                message.hidden = state === 'available' || state === 'checking';
                message.classList.toggle('is-unavailable', unavailable);
                message.textContent = descriptions[state] || '';
            }
            if (button) {
                button.textContent = unavailable ? 'Выбрать новое время'
                    : (unknown ? 'Повторить проверку'
                        : (state === 'checking' ? 'Проверяем время…'
                            : (button.tagName === 'A' ? 'Войти и отправить' : 'Отправить заявку')));
                if (button.tagName === 'BUTTON') button.disabled = state === 'checking';
                button.setAttribute('aria-busy', String(state === 'checking'));
            }
        }

        async function checkAvailability() {
            if (stopped) return;
            if (Number.isFinite(expiresAt.getTime()) && Date.now() >= expiresAt.getTime()) {
                ++requestSequence;
                updateState('expired');
                return;
            }
            const sequence = ++requestSequence;
            try {
                const url = new URL(monitor.dataset.quoteStatusUrl, window.location.href);
                url.searchParams.set('quote_id', monitor.dataset.quoteId);
                const result = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {Accept: 'application/json'},
                    cache: 'no-store',
                });
                if (!result.ok) throw new Error('Status request failed');
                const data = await result.json();
                if (stopped || sequence !== requestSequence) return;
                updateState(data.available ? 'available'
                    : (data.reason === 'expired' ? 'expired' : 'unavailable'));
            } catch (_) {
                if (!stopped && sequence === requestSequence) updateState('unknown');
            }
        }

        function openConflict() {
            if (!conflict) return;
            if (conflictMessage) conflictMessage.textContent = descriptions[state] || descriptions.unavailable;
            if (!conflict.open) conflict.showModal();
        }

        const handleAttempt = event => {
            if (state === 'available') return;
            event.preventDefault();
            if (state === 'unknown') void checkAvailability();
            else if (state === 'unavailable' || state === 'expired') openConflict();
        };
        sendForm?.addEventListener('submit', handleAttempt);
        if (!sendForm) button?.addEventListener('click', handleAttempt);
        closeConflict?.addEventListener('click', () => conflict?.close());
        conflict?.addEventListener('click', event => {
            if (event.target === conflict) conflict.close();
        });
        conflictChoose?.addEventListener('click', () => {
            conflict?.close();
            openCalendar(chosenCourt);
        });

        updateState('checking');
        void checkAvailability();
        const unsubscribe = subscribePublic(
            `venue-availability.${venueId}`,
            '.availability.updated',
            event => {
                if ((event.court_id === null || Number(event.court_id) === courtId)
                    && (!event.date || event.date === quotedDate)) {
                    void checkAvailability();
                }
            },
        );
        const refreshWhenVisible = () => {
            if (!document.hidden) void checkAvailability();
        };
        window.addEventListener('mskba:realtime-state', refreshWhenVisible);
        window.addEventListener('focus', refreshWhenVisible);
        document.addEventListener('visibilitychange', refreshWhenVisible);
        const interval = window.setInterval(refreshWhenVisible, 15000);
        window.addEventListener('pagehide', () => {
            stopped = true;
            unsubscribe();
            window.clearInterval(interval);
            window.removeEventListener('mskba:realtime-state', refreshWhenVisible);
            window.removeEventListener('focus', refreshWhenVisible);
            document.removeEventListener('visibilitychange', refreshWhenVisible);
        }, {once: true});
    }
}
