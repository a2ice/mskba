import { loadYandexMaps } from '../core/yandex-maps.js';

document.addEventListener('DOMContentLoaded', () => {
    initEventHero();
    initEventVenueMap();
    initEventDescription();
    initEventShare();
    const miniGames = initMiniGameManagement();
    initEventParticipantManagement(miniGames);
});

function initEventHero() {
    const hero = document.querySelector('[data-event-hero]');
    const track = hero?.querySelector('[data-event-hero-track]');
    const slides = Array.from(hero?.querySelectorAll('[data-event-hero-slide]') || []);
    const dots = Array.from(document.querySelectorAll('[data-event-hero-dot]'));
    const counter = hero?.querySelector('[data-event-hero-counter]');

    if (!track || slides.length < 2) {
        return;
    }

    const select = (index) => {
        const normalizedIndex = Math.max(0, Math.min(slides.length - 1, index));
        dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === normalizedIndex));

        if (counter) {
            counter.textContent = `${normalizedIndex + 1} / ${slides.length}`;
        }
    };

    let frame = null;

    track.addEventListener('scroll', () => {
        if (frame !== null) {
            cancelAnimationFrame(frame);
        }

        frame = requestAnimationFrame(() => {
            const index = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            select(index);
        });
    }, { passive: true });

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            track.scrollTo({ left: track.clientWidth * index, behavior: 'smooth' });
            select(index);
        });
    });
}

function initEventVenueMap() {
    const openButton = document.querySelector('[data-event-map-open]');
    const mapElement = document.querySelector('[data-event-map]');
    const message = document.querySelector('[data-event-map-message]');
    let yandexMap = null;
    let loading = null;

    if (!openButton || !mapElement || !message) {
        return;
    }

    openButton.addEventListener('click', () => {
        window.setTimeout(loadMap, 0);
    });

    async function loadMap() {
        if (yandexMap) {
            yandexMap.container.fitToViewport();
            return;
        }

        if (loading) {
            return loading;
        }

        message.textContent = 'Загружаем карту…';
        message.hidden = false;
        loading = createMap();

        try {
            await loading;
            message.hidden = true;
        } catch (error) {
            message.textContent = error?.message || 'Не удалось загрузить карту.';
        } finally {
            loading = null;
        }
    }

    async function createMap() {
        const apiKey = mapElement.dataset.yandexMapApiKey;
        const latitude = Number.parseFloat(mapElement.dataset.latitude || '');
        const longitude = Number.parseFloat(mapElement.dataset.longitude || '');

        if (!apiKey) {
            throw new Error('Ключ Яндекс Карт не настроен.');
        }

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            throw new Error('Координаты площадки пока не указаны.');
        }

        await loadYandexMaps(apiKey);
        await new Promise((resolve) => window.ymaps.ready(resolve));

        const center = [latitude, longitude];
        yandexMap = new window.ymaps.Map(mapElement, {
            center,
            zoom: 15,
            controls: ['zoomControl', 'fullscreenControl'],
        });
        yandexMap.geoObjects.add(new window.ymaps.Placemark(center, {
            balloonContentHeader: mapElement.dataset.title || 'Площадка',
            balloonContentBody: mapElement.dataset.address || '',
            hintContent: mapElement.dataset.title || 'Площадка',
        }, {
            preset: 'islands#orangeSportIcon',
        }));
    }
}

function initEventDescription() {
    const container = document.querySelector('[data-event-description]');
    const text = container?.querySelector('[data-event-description-text]');
    const toggle = container?.querySelector('[data-event-description-toggle]');

    if (!text || !toggle) {
        return;
    }

    text.classList.add('is-collapsed');

    requestAnimationFrame(() => {
        const isOverflowing = text.scrollHeight > text.clientHeight + 2;

        if (!isOverflowing) {
            text.classList.remove('is-collapsed');
            return;
        }

        toggle.hidden = false;
    });

    toggle.addEventListener('click', () => {
        const isOpen = toggle.classList.toggle('is-open');
        text.classList.toggle('is-collapsed', !isOpen);
        toggle.querySelector('span').textContent = isOpen ? 'Скрыть' : 'Показать больше';
    });
}

function initEventShare() {
    const button = document.querySelector('[data-event-share]');

    if (!button) {
        return;
    }

    button.addEventListener('click', async () => {
        const url = button.dataset.shareUrl || window.location.href;
        const title = button.dataset.shareTitle || document.title;
        const telegram = window.Telegram?.WebApp;

        if (telegram && typeof telegram.openTelegramLink === 'function') {
            const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title)}`;
            telegram.openTelegramLink(shareUrl);
            return;
        }

        if (navigator.share) {
            try {
                await navigator.share({ title, url });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
            }
        }

        try {
            await navigator.clipboard.writeText(url);
            const subtitle = button.querySelector('small');

            if (subtitle) {
                const originalText = subtitle.textContent;
                subtitle.textContent = 'Ссылка скопирована';
                window.setTimeout(() => {
                    subtitle.textContent = originalText;
                }, 2200);
            }
        } catch {
            window.prompt('Скопируйте ссылку на мероприятие', url);
        }
    });
}

function initMiniGameManagement() {
    const section = document.querySelector('[data-event-mini-games]');
    const form = section?.querySelector('[data-mini-game-form]');
    const format = section?.querySelector('[data-mini-game-format]');
    const sideASize = section?.querySelector('[data-mini-game-side-a-size]');
    const sideBSize = section?.querySelector('[data-mini-game-side-b-size]');
    const empty = section?.querySelector('[data-mini-game-empty]');
    const overlay = section?.querySelector('[data-image-upload-overlay]');

    if (!section || !form || !format || !sideASize || !sideBSize || !empty) {
        return null;
    }

    let confirmedCount = Number.parseInt(section.dataset.confirmedCount || '0', 10);

    const setLoading = (loading) => {
        if (!overlay) {
            return;
        }

        overlay.hidden = !loading;
        section.classList.toggle('is-image-upload-loading', loading);
    };

    const availableFormats = () => {
        const formats = [];
        const maximumPlayers = Math.min(11, confirmedCount);

        for (let players = 2; players <= maximumPlayers; players += 1) {
            const sideA = Math.ceil(players / 2);
            const sideB = Math.floor(players / 2);

            if (sideA <= 6 && sideB <= 5) {
                formats.push({ sideA, sideB });
            }
        }

        return formats;
    };

    const syncFormatInputs = () => {
        const option = format.selectedOptions[0];

        if (!option) {
            return;
        }

        sideASize.value = option.dataset.sideA || '';
        sideBSize.value = option.dataset.sideB || '';
    };

    const renderFormats = () => {
        const current = format.value;
        const formats = availableFormats();
        format.replaceChildren();

        formats.forEach(({ sideA, sideB }) => {
            const option = document.createElement('option');
            option.value = `${sideA}x${sideB}`;
            option.dataset.sideA = String(sideA);
            option.dataset.sideB = String(sideB);
            option.textContent = `${sideA}×${sideB}`;
            format.append(option);
        });

        if (formats.some(({ sideA, sideB }) => `${sideA}x${sideB}` === current)) {
            format.value = current;
        } else if (formats.length > 0) {
            const largestFormat = formats[formats.length - 1];
            format.value = `${largestFormat.sideA}x${largestFormat.sideB}`;
        }

        const hasEnoughPlayers = formats.length > 0;
        form.hidden = !hasEnoughPlayers;
        empty.hidden = hasEnoughPlayers;
        syncFormatInputs();
    };

    const createPlayerToggle = (participant, side) => {
        const wrapper = document.createElement('div');
        const label = document.createElement('label');
        const input = document.createElement('input');
        const control = document.createElement('span');
        const title = document.createElement('strong');
        const sideKey = side.toLowerCase();

        wrapper.className = 'game-roster-toggle';
        label.className = 'form-toggle';
        label.htmlFor = `mini-game-side-${sideKey}-${participant.user_id}`;
        input.id = label.htmlFor;
        input.className = 'form-toggle__input';
        input.type = 'checkbox';
        input.name = `side_${sideKey}_user_ids[]`;
        input.value = String(participant.user_id);
        input.dataset.miniGamePlayerToggle = '';
        input.dataset.playerId = String(participant.user_id);
        input.dataset.side = side;
        control.className = 'form-toggle__control';
        control.setAttribute('aria-hidden', 'true');
        title.className = 'form-toggle__title';
        title.textContent = participant.name;
        label.append(input, control, title);
        wrapper.append(label);

        return wrapper;
    };

    const addParticipant = (participant) => {
        ['A', 'B'].forEach((side) => {
            const roster = section.querySelector(`[data-mini-game-roster="${side}"]`);

            if (roster && !section.querySelector(`[data-mini-game-player-toggle][data-side="${side}"][data-player-id="${participant.user_id}"]`)) {
                roster.append(createPlayerToggle(participant, side));
            }
        });
        confirmedCount += 1;
        section.dataset.confirmedCount = String(confirmedCount);
        renderFormats();
    };

    format.addEventListener('change', syncFormatInputs);
    section.addEventListener('change', (event) => {
        const toggle = event.target instanceof Element
            ? event.target.closest('[data-mini-game-player-toggle]')
            : null;

        if (!toggle?.checked) {
            return;
        }

        const otherSide = toggle.dataset.side === 'A' ? 'B' : 'A';
        const otherToggle = section.querySelector(
            `[data-mini-game-player-toggle][data-side="${otherSide}"][data-player-id="${toggle.dataset.playerId}"]`,
        );

        if (otherToggle) {
            otherToggle.checked = false;
        }
    });
    renderFormats();

    return { addParticipant, setLoading };
}

function initEventParticipantManagement(miniGames) {
    const manager = document.querySelector('[data-event-participant-manager]');
    const form = manager?.querySelector('[data-event-participant-form]');
    const input = manager?.querySelector('[data-event-participant-search]');
    const userId = manager?.querySelector('[data-event-participant-user-id]');
    const results = manager?.querySelector('[data-event-participant-results]');
    const control = manager?.querySelector('[data-event-participant-control]');
    const message = manager?.querySelector('[data-event-participant-message]');
    const selection = manager?.querySelector('[data-event-participant-selection]');
    const status = manager?.querySelector('[data-event-participant-status]');
    const submitButton = manager?.querySelector('[data-event-participant-submit]');
    const searchUrl = manager?.dataset.searchUrl;
    const focusTriggers = document.querySelectorAll('[data-event-participant-focus]');

    if (!form || !input || !userId || !results || !control || !message || !selection || !status || !submitButton || !searchUrl) {
        return;
    }

    focusTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            window.requestAnimationFrame(() => {
                input.focus({ preventScroll: true });
            });
        });
    });

    let debounceTimer = null;
    let requestController = null;
    let selectedName = '';

    const setControlState = (state) => {
        control.hidden = state === 'hidden';
        control.disabled = state === 'loading';
        control.classList.toggle('is-loading', state === 'loading');
        control.setAttribute(
            'aria-label',
            state === 'loading' ? 'Идёт поиск пользователей' : 'Очистить поиск',
        );
        input.setAttribute('aria-busy', String(state === 'loading'));
    };

    const updateControl = () => {
        setControlState(input.value ? 'clear' : 'hidden');
    };

    const showMessage = (text) => {
        message.textContent = text;
        message.classList.remove('d-none');
    };

    const hideMessage = () => {
        message.textContent = '';
        message.classList.add('d-none');
    };

    const showStatus = (text) => {
        status.textContent = text;
        status.hidden = false;
    };

    const resetSelection = () => {
        selectedName = '';
        userId.value = '';
        selection.textContent = '';
        selection.hidden = true;
        submitButton.disabled = true;
    };

    const hideResults = () => {
        results.classList.add('d-none');
        results.replaceChildren();
    };

    const reset = () => {
        requestController?.abort();
        window.clearTimeout(debounceTimer);
        input.value = '';
        resetSelection();
        hideResults();
        hideMessage();
        updateControl();
        input.focus();
    };

    const selectUser = (user) => {
        selectedName = user.name;
        userId.value = String(user.id);
        input.value = user.username ? `${user.name} · @${user.username}` : user.name;
        selection.textContent = `Выбран: ${input.value}`;
        selection.hidden = false;
        submitButton.disabled = false;
        hideResults();
        hideMessage();
        updateControl();
    };

    const renderResults = (users) => {
        results.replaceChildren();

        if (!users.length) {
            hideResults();
            showMessage('Варианты не найдены.');
            return;
        } else {
            users.forEach((user) => {
                const option = document.createElement('button');
                const name = document.createElement('strong');
                const username = document.createElement('span');

                option.type = 'button';
                option.className = 'predictive-search__item event-participant-search__option';
                option.setAttribute('role', 'option');
                name.className = 'predictive-search__label';
                name.textContent = user.name;
                username.className = 'predictive-search__meta';
                username.textContent = user.username ? `@${user.username}` : `ID ${user.id}`;
                option.append(name, username);
                option.addEventListener('click', () => selectUser(user));
                results.append(option);
            });
        }

        hideMessage();
        results.classList.remove('d-none');
    };

    const search = async () => {
        const query = input.value.trim();

        if (query.length < 2) {
            hideResults();
            hideMessage();
            updateControl();
            return;
        }

        requestController?.abort();
        const controller = new AbortController();
        requestController = controller;
        hideMessage();
        setControlState('loading');

        try {
            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('query', query);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось выполнить поиск.');
            }

            renderResults(payload.users || []);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            hideResults();
            showMessage(error?.message || 'Не удалось выполнить поиск.');
        } finally {
            if (requestController === controller) {
                requestController = null;
                updateControl();
            }
        }
    };

    input.addEventListener('input', () => {
        if (selectedName !== '' || userId.value !== '') {
            resetSelection();
        }

        requestController?.abort();
        requestController = null;
        hideMessage();
        updateControl();
        window.clearTimeout(debounceTimer);

        if (input.value.trim().length < 2) {
            hideResults();
            updateControl();
            return;
        }

        setControlState('loading');
        debounceTimer = window.setTimeout(search, 250);
    });

    input.addEventListener('focus', () => {
        if (results.childElementCount > 0 && userId.value === '') {
            results.classList.remove('d-none');
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            const firstOption = results.querySelector('.predictive-search__item');
            if (firstOption) {
                event.preventDefault();
                firstOption.focus();
            }
        }

        if (event.key === 'Escape') {
            hideResults();
        }
    });

    results.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideResults();
            input.focus();
        }
    });

    control.addEventListener('click', reset);
    document.addEventListener('click', (event) => {
        if (!manager.contains(event.target)) {
            hideResults();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (userId.value === '') {
            input.focus();
            return;
        }

        hideMessage();
        status.hidden = true;
        submitButton.disabled = true;
        miniGames?.setLoading(true);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось добавить участника.');
            }

            miniGames?.addParticipant(payload.participant);
            showStatus(payload.message || 'Участник добавлен в мероприятие.');
            reset();
        } catch (error) {
            showMessage(error?.message || 'Не удалось добавить участника.');
            submitButton.disabled = userId.value === '';
        } finally {
            miniGames?.setLoading(false);
        }
    });
}
