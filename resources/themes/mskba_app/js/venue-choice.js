// A single decision before rent vs. favorite. Existing booking and favorites APIs
// remain authoritative; this module does not manufacture a booking or a PlayIntent.
(() => {
    const choice = document.querySelector('[data-venue-choice-dialog]');
    const favorite = document.querySelector('[data-venue-favorite-dialog]');
    const dayDialog = document.querySelector('[data-venue-day-dialog]');
    if (!choice || !favorite || !dayDialog) return;

    const rental = choice.querySelector('[data-venue-choice-rental]');
    const favoriteOption = choice.querySelector('[data-venue-choice-favorite]');
    const courtSelect = choice.querySelector('[data-venue-choice-court]');
    const courtError = choice.querySelector('[data-venue-choice-error]');
    const favoriteError = favorite.querySelector('[data-venue-favorite-error]');
    const favoriteDescription = favorite.querySelector('[data-venue-favorite-description]');
    const favoriteSubmit = favorite.querySelector('[data-venue-favorite-submit]');
    const venueId = Number(favorite.dataset.venueId);
    let desiredDate = null;
    let favoriteState = 'unknown';
    let busy = false;
    const favoriteResumeKey = 'mskba:venue-favorite-auth-resume';
    let awaitingFavoriteAuth = false;
    const authenticated = () => favorite.dataset.authenticated === '1'
        || document.querySelector('meta[name="mskba-authenticated"]')?.content === '1';
    const saveFavoriteResume = () => {
        try {
            sessionStorage.setItem(favoriteResumeKey, JSON.stringify({
                path: location.pathname, venueId, savedAt: Date.now(),
            }));
        } catch { /* optional browser storage */ }
    };
    const clearFavoriteResume = () => {
        try {sessionStorage.removeItem(favoriteResumeKey);} catch { /* optional */ }
    };
    const readFavoriteResume = () => {
        try {
            const entry = JSON.parse(sessionStorage.getItem(favoriteResumeKey) || 'null');
            return entry?.path === location.pathname && entry?.venueId === venueId
                && Date.now() - entry.savedAt < 20 * 60 * 1000;
        } catch {return false;}
    };

    const notify = (message, failed = false) => window.dispatchEvent(
        new CustomEvent('mskba:action-toast', {detail: {message, failed}})
    );
    const json = async (url, method = 'GET', data = null) => {
        const response = await fetch(url, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            ...(data ? {body: JSON.stringify(data)} : {}),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw Error(body.message || (response.status === 419
                ? 'Сессия истекла. Обновите страницу.'
                : 'Не удалось сохранить избранное. Попробуйте снова.'));
        }
        return body;
    };
    const setFavoriteError = message => {
        favoriteError.textContent = message || '';
        favoriteError.hidden = !message;
    };
    function close(dialog) {
        if (dialog.open) dialog.close();
    }
    function openChoice(date = null, expandRental = false) {
        desiredDate = date;
        rental.open = expandRental;
        favoriteOption.open = false;
        courtError.hidden = true;
        // Nested dialogs are not left stacked above the selected action.
        close(document.querySelector('[data-venue-interests-dialog]') || choice);
        if (!choice.open) choice.showModal();
    }
    function requestFavorite() {
        close(choice);
        if (!authenticated()) {
            awaitingFavoriteAuth = true;
            saveFavoriteResume();
            window.dispatchEvent(new CustomEvent('mskba:auth:open', {
                detail: {mode: 'login', stayOnPage: true},
            }));
            return;
        }
        void openFavorite();
    }
    async function openFavorite() {
        close(choice);
        favoriteState = 'unknown';
        favoriteSubmit.disabled = true;
        favoriteSubmit.textContent = 'Проверяем…';
        favoriteDescription.textContent = 'Сохрани площадку, чтобы быстро возвращаться к её расписанию. Для вошедших пользователей это прямой интерес: другие посетители увидят логин, но не аватар или игровые характеристики без отдельного согласия. Это не заявка на аренду.';
        setFavoriteError('');
        if (!favorite.open) favorite.showModal();
        try {
            const url = new URL(favorite.dataset.statusUrl, location.href);
            url.searchParams.append('ids[]', String(venueId));
            const data = await json(url);
            if (!favorite.open) return;
            favoriteState = data.data?.[venueId]?.state || 'none';
            const saved = favoriteState === 'active';
            favoriteDescription.textContent = saved
                ? 'Площадка уже у тебя в избранном. При желании её можно убрать.'
                : 'Добавь площадку в избранное, чтобы вернуться к ней позже. Для вошедших пользователей это также прямой интерес; логин будет виден другим посетителям. Бронирование при этом не создаётся.';
            favoriteSubmit.textContent = saved ? 'Убрать из избранного' : 'Добавить в избранное';
            favoriteSubmit.disabled = false;
        } catch (error) {
            if (favorite.open) setFavoriteError(error.message);
        }
    }
    document.addEventListener('click', event => {
        const opener = event.target.closest?.('[data-venue-choice-open]');
        if (!opener || opener.disabled) return;
        event.preventDefault();
        openChoice();
    });
    window.addEventListener('mskba:venue-court-selection-required', event => {
        openChoice(event.detail?.date || null, true);
    });
    favoriteOption.addEventListener('toggle', () => {
        if (!favoriteOption.open || !choice.open) return;
        favoriteOption.open = false;
        requestFavorite();
    });
    favorite.querySelector('[data-venue-favorite-back]')?.addEventListener('click', () => {
        close(favorite);
        openChoice();
    });
    window.addEventListener('mskba:auth:success', () => {
        if (!awaitingFavoriteAuth && !readFavoriteResume()) return;
        awaitingFavoriteAuth = false;
        favorite.dataset.authenticated = '1';
        clearFavoriteResume();
        void openFavorite();
    });
    // Registration and external authentication can redirect back to this page.
    // Resume the confirmation, never silently add a favorite.
    if (authenticated() && readFavoriteResume()) {
        clearFavoriteResume();
        queueMicrotask(() => void openFavorite());
    }
    choice.querySelectorAll('[data-venue-choice-close]').forEach(button =>
        button.addEventListener('click', () => close(choice)));
    favorite.querySelectorAll('[data-venue-favorite-close]').forEach(button =>
        button.addEventListener('click', () => close(favorite)));
    for (const dialog of [choice, favorite]) {
        dialog.addEventListener('click', event => {
            if (event.target === dialog) close(dialog);
        });
    }
    courtSelect?.addEventListener('change', () => {courtError.hidden = true;});
    choice.querySelector('[data-venue-choice-date]')?.addEventListener('click', () => {
        const courtId = Number(courtSelect?.value);
        if (!Number.isSafeInteger(courtId) || courtId < 1) {
            courtError.hidden = false;
            courtSelect?.focus();
            return;
        }
        close(choice);
        dayDialog.dispatchEvent(new CustomEvent('mskba:choose-court', {
            detail: {courtId, date: desiredDate},
        }));
    });
    favoriteSubmit.addEventListener('click', async () => {
        if (busy || favoriteState === 'unknown') return;
        busy = true;
        favoriteSubmit.disabled = true;
        setFavoriteError('');
        try {
            const result = await json(favorite.dataset.toggleUrl, 'POST', {type: 'venue', id: venueId});
            favoriteState = result.state;
            window.dispatchEvent(new CustomEvent('mskba:favorites:changed', {
                detail: {count: result.favorites_count},
            }));
            window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated', {
                detail: {venueId, state: result.state, count: result.count},
            }));
            close(favorite);
            notify(result.state === 'active'
                ? 'Площадка добавлена в избранное'
                : 'Площадка убрана из избранного');
        } catch (error) {
            setFavoriteError(error.message);
        } finally {
            busy = false;
            favoriteSubmit.disabled = favoriteState === 'unknown';
        }
    });
})();
