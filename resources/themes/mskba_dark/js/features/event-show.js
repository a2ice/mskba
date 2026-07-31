import { loadYandexMaps } from '../core/yandex-maps.js';
import { setImageUploadLoading } from './image-upload.js';

document.addEventListener('DOMContentLoaded', () => {
    initEventHero();
    initEventVenueMap();
    initEventDescription();
    initEventShare();
    initMiniGameScheduleControls();
    initResponsibilityPermissions();
    initGameStatistics();
    const miniGames = initMiniGameManagement();
    initEventParticipantManagement(miniGames);
    initEventResultPhotoEditors();
});

function parseJsonDataset(value, fallback = []) {
    try {
        return JSON.parse(value || '');
    } catch {
        return fallback;
    }
}

function initEventResultPhotoEditors() {
    document.querySelectorAll('[data-event-photo-editor]').forEach((editor) => {
        const form = editor.querySelector('[data-event-photo-metadata-form]');
        const surface = editor.querySelector('[data-event-photo-tag-surface]');
        const tagsLayer = editor.querySelector('[data-event-photo-tags]');
        const search = editor.querySelector('[data-event-photo-tag-search]');
        const suggestions = editor.querySelector('[data-event-photo-tag-suggestions]');
        const hint = editor.querySelector('[data-event-photo-tag-hint]');
        const status = editor.querySelector('[data-event-photo-status]');
        const candidates = parseJsonDataset(editor.dataset.candidates);
        let tags = parseJsonDataset(editor.dataset.tags);
        let selectedCandidate = null;

        if (!form || !surface || !tagsLayer || !search || !suggestions) {
            return;
        }

        const renderTags = () => {
            tagsLayer.replaceChildren();
            tags.forEach((tag) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'event-photo-tag is-visible';
                button.style.setProperty('--tag-x', `${tag.x}%`);
                button.style.setProperty('--tag-y', `${tag.y}%`);
                button.title = `Удалить отметку: ${tag.name}`;
                button.setAttribute('aria-label', `Удалить отметку участника ${tag.name}`);
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    tags = tags.filter((item) => Number(item.user_id) !== Number(tag.user_id));
                    renderTags();
                });
                tagsLayer.append(button);
            });
        };

        const closeSuggestions = () => {
            suggestions.hidden = true;
            suggestions.replaceChildren();
        };

        const renderSuggestions = () => {
            const query = search.value.trim();
            selectedCandidate = null;
            if (!query.startsWith('@')) {
                closeSuggestions();
                return;
            }

            const needle = query.slice(1).trim().toLocaleLowerCase('ru');
            const matches = candidates.filter((candidate) => {
                if (tags.some((tag) => Number(tag.user_id) === Number(candidate.id))) {
                    return false;
                }
                return !needle
                    || candidate.name.toLocaleLowerCase('ru').includes(needle)
                    || (candidate.username || '').toLocaleLowerCase('ru').includes(needle);
            }).slice(0, 8);

            suggestions.replaceChildren();
            matches.forEach((candidate) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = candidate.username
                    ? `${candidate.name} · @${candidate.username}`
                    : candidate.name;
                button.addEventListener('click', () => {
                    selectedCandidate = candidate;
                    search.value = `@${candidate.username || candidate.name}`;
                    closeSuggestions();
                    hint.textContent = `Нажмите на фотографию, чтобы отметить: ${candidate.name}`;
                    surface.classList.add('is-awaiting-tag-position');
                });
                suggestions.append(button);
            });
            suggestions.hidden = matches.length === 0;
        };

        search.addEventListener('input', renderSuggestions);
        search.addEventListener('focus', renderSuggestions);
        document.addEventListener('click', (event) => {
            if (!editor.contains(event.target)) closeSuggestions();
        });

        surface.addEventListener('click', (event) => {
            if (!selectedCandidate) return;
            const bounds = surface.getBoundingClientRect();
            const x = Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100));
            const y = Math.max(0, Math.min(100, ((event.clientY - bounds.top) / bounds.height) * 100));
            tags.push({
                user_id: selectedCandidate.id,
                name: selectedCandidate.name,
                username: selectedCandidate.username,
                x,
                y,
            });
            selectedCandidate = null;
            search.value = '';
            hint.textContent = 'Выберите участника, затем нажмите на нужное место фотографии.';
            surface.classList.remove('is-awaiting-tag-position');
            renderTags();
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            setImageUploadLoading(form, true);
            status.textContent = '';
            status.classList.remove('is-error');

            try {
                const response = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        description: form.querySelector('[name="description"]')?.value || null,
                        tags: tags.map((tag) => ({ user_id: tag.user_id, x: tag.x, y: tag.y })),
                    }),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Не удалось сохранить фотографию.');
                tags = payload.tags || tags;
                editor.dataset.tags = JSON.stringify(tags);
                renderTags();
                status.textContent = payload.message || 'Сохранено.';
            } catch (error) {
                status.textContent = error?.message || 'Не удалось сохранить фотографию.';
                status.classList.add('is-error');
            } finally {
                setImageUploadLoading(form, false);
            }
        });

        renderTags();
    });
}

function initResponsibilityPermissions() {
    document.querySelectorAll('[data-responsibility-permissions]').forEach((editor) => {
        const groups = Array.from(editor.querySelectorAll('[data-permission-group]'));
        const score = editor.querySelector('[value="mini_game.score.manage"]');
        const statistics = editor.querySelector('[value="mini_game.statistics.manage"]');

        const syncGroup = (group) => {
            const toggle = group.querySelector('[data-permission-group-toggle]');
            const options = Array.from(group.querySelectorAll('[data-permission-option]'));
            toggle.checked = options.length > 0 && options.every((option) => option.checked);
            toggle.indeterminate = options.some((option) => option.checked) && !toggle.checked;
        };

        groups.forEach((group) => {
            const toggle = group.querySelector('[data-permission-group-toggle]');
            const options = Array.from(group.querySelectorAll('[data-permission-option]'));
            toggle.addEventListener('change', () => {
                options.forEach((option) => { option.checked = toggle.checked; });
                if (!toggle.checked && group.dataset.permissionGroup === 'mini_game') {
                    statistics && (statistics.checked = false);
                }
                syncGroup(group);
            });
            options.forEach((option) => option.addEventListener('change', () => {
                if (option === statistics && statistics.checked && score) {
                    score.checked = true;
                }
                if (option === score && !score.checked && statistics) {
                    statistics.checked = false;
                }
                syncGroup(group);
            }));
            syncGroup(group);
        });
    });
}

function initGameStatistics() {
    const form = document.querySelector('[data-game-statistics-form]');

    if (!form) {
        return;
    }

    const rows = Array.from(form.querySelectorAll('[data-game-statistics-row]'));
    const scoreInputs = new Map(
        Array.from(form.querySelectorAll('[data-game-score]'))
            .map((input) => [input.dataset.gameScore, input]),
    );
    const calculatedLabels = new Map(
        Array.from(form.querySelectorAll('[data-game-calculated-score]'))
            .map((label) => [label.dataset.gameCalculatedScore, label]),
    );
    const calculateButton = form.querySelector('[data-game-statistics-calculate]');
    const submitButton = form.querySelector('[data-game-statistics-submit]');
    const completeButton = form.querySelector('[data-game-statistics-complete]');
    const overlay = form.querySelector('[data-image-upload-overlay]');
    const message = document.querySelector('[data-game-statistics-message]');

    const integerValue = (value) => {
        const parsed = Number.parseInt(value, 10);

        return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
    };

    const rowPoints = (row) => {
        const value = (field) => integerValue(
            row.querySelector(`[data-game-statistic-field="${field}"]`)?.value,
        );
        const points = (value('close_made') * 2)
            + (value('mid_made') * 2)
            + (value('three_made') * 3)
            + value('free_throw_made');
        const output = row.querySelector('[data-game-player-points]');

        if (output) {
            output.textContent = String(points);
        }

        return points;
    };

    const calculatedScores = () => {
        const scores = { A: 0, B: 0 };

        rows.forEach((row) => {
            const side = row.dataset.side;

            if (side === 'A' || side === 'B') {
                scores[side] += rowPoints(row);
            }
        });

        return scores;
    };

    const renderCalculatedScore = (side, calculated) => {
        const scoreInput = scoreInputs.get(side);
        const label = calculatedLabels.get(side);

        if (!scoreInput || !label) {
            return;
        }

        const differs = integerValue(scoreInput.value) !== calculated;
        label.classList.toggle('is-mismatch', differs);
        label.textContent = differs
            ? `По игрокам: ${calculated} · проверьте расхождение`
            : `По игрокам: ${calculated}`;
    };

    const refresh = ({ overwriteScores = false } = {}) => {
        const scores = calculatedScores();

        Object.entries(scores).forEach(([side, calculated]) => {
            const scoreInput = scoreInputs.get(side);

            if (scoreInput && (overwriteScores || scoreInput.dataset.manualOverride !== 'true')) {
                scoreInput.value = String(calculated);
            }

            if (overwriteScores && scoreInput) {
                scoreInput.dataset.manualOverride = 'false';
            }

            renderCalculatedScore(side, calculated);
        });

        return scores;
    };

    const setLoading = (loading) => {
        form.classList.toggle('is-image-upload-loading', loading);

        if (overlay) {
            overlay.hidden = !loading;
        }

        [calculateButton, submitButton, completeButton].forEach((button) => {
            if (button) {
                button.disabled = loading;
            }
        });
    };

    const showMessage = (text, variant) => {
        if (!message) {
            return;
        }

        message.hidden = false;
        message.textContent = text;
        message.classList.remove('alert--success', 'alert--danger');
        message.classList.add(variant === 'success' ? 'alert--success' : 'alert--danger');
    };

    const responsePayload = async (response) => {
        try {
            return await response.json();
        } catch {
            return {};
        }
    };

    const responseError = (payload) => {
        const validationError = Object.values(payload.errors || {}).flat()[0];

        return validationError || payload.message || 'Не удалось сохранить статистику.';
    };

    const initialCalculated = calculatedScores();
    Object.entries(initialCalculated).forEach(([side, calculated]) => {
        const scoreInput = scoreInputs.get(side);

        if (scoreInput) {
            scoreInput.dataset.manualOverride = integerValue(scoreInput.value) === calculated
                ? 'false'
                : 'true';
        }

        renderCalculatedScore(side, calculated);
    });

    rows.forEach((row) => {
        row.querySelectorAll('[data-game-statistic-field]').forEach((input) => {
            input.addEventListener('input', () => refresh());
        });
    });

    scoreInputs.forEach((input, side) => {
        input.addEventListener('input', () => {
            input.dataset.manualOverride = 'true';
            renderCalculatedScore(side, calculatedScores()[side]);
        });
    });

    calculateButton?.addEventListener('click', () => {
        refresh({ overwriteScores: true });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const completesGame = event.submitter?.matches('[data-game-statistics-complete]') === true;
        const action = completesGame
            ? event.submitter.dataset.completeUrl
            : form.action;

        if (completesGame) {
            const scoreA = integerValue(scoreInputs.get('A')?.value);
            const scoreB = integerValue(scoreInputs.get('B')?.value);
            const nameA = event.submitter.dataset.sideAName || 'Команда A';
            const nameB = event.submitter.dataset.sideBName || 'Команда B';
            let result;

            if (scoreA === scoreB) {
                result = `${nameA} и ${nameB} сыграли вничью со счётом ${scoreA}:${scoreB}!`;
            } else {
                const winner = scoreA > scoreB ? nameA : nameB;
                const loser = scoreA > scoreB ? nameB : nameA;
                const winnerScore = Math.max(scoreA, scoreB);
                const loserScore = Math.min(scoreA, scoreB);

                result = `${winner} выиграла у ${loser} со счётом ${winnerScore}:${loserScore}!`;
            }

            if (!window.confirm(`${result}\n\nВы уверены, что хотите завершить игру?`)) {
                return;
            }
        }

        setLoading(true);

        if (message) {
            message.hidden = true;
        }

        try {
            const response = await fetch(action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await responsePayload(response);

            if (!response.ok) {
                throw new Error(responseError(payload));
            }

            Object.entries(payload.scores || {}).forEach(([side, score]) => {
                const input = scoreInputs.get(side);

                if (input) {
                    input.value = String(score);
                }
            });
            Object.entries(payload.player_points || {}).forEach(([userId, points]) => {
                const output = rows
                    .find((row) => row.dataset.playerId === String(userId))
                    ?.querySelector('[data-game-player-points]');

                if (output) {
                    output.textContent = String(points);
                }
            });
            Object.entries(payload.calculated_scores || {}).forEach(([side, calculated]) => {
                const input = scoreInputs.get(side);

                if (input) {
                    input.dataset.manualOverride = integerValue(input.value) === Number(calculated)
                        ? 'false'
                        : 'true';
                }

                renderCalculatedScore(side, Number(calculated));
            });
            showMessage(payload.message || 'Статистика сохранена.', 'success');

            if (payload.completed && payload.redirect_url) {
                window.location.assign(payload.redirect_url);
            }
        } catch (error) {
            showMessage(error?.message || 'Не удалось сохранить статистику.', 'danger');
        } finally {
            setLoading(false);
        }
    });
}

function initEventHero() {
    const hero = document.querySelector('[data-event-hero]');
    const track = hero?.querySelector('[data-event-hero-track]');
    const slides = Array.from(hero?.querySelectorAll('[data-event-hero-slide]') || []);
    const dots = Array.from(document.querySelectorAll('[data-event-hero-dot]'));
    const counter = hero?.querySelector('[data-event-hero-counter]');

    slides.filter((slide) => slide.hasAttribute('data-photo-tags-toggle')).forEach((slide) => {
        const toggle = () => slide.classList.toggle('is-tags-visible');
        slide.addEventListener('click', toggle);
        slide.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    });

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
    const openButtons = Array.from(document.querySelectorAll('[data-event-map-open], [data-catalog-map-open]'));
    const mapElement = document.querySelector('[data-event-map]');
    const message = document.querySelector('[data-event-map-message]');
    const title = document.querySelector('[data-catalog-map-title]');
    let yandexMap = null;
    let loading = null;

    if (openButtons.length === 0 || !mapElement || !message) {
        return;
    }

    openButtons.forEach((openButton) => openButton.addEventListener('click', () => {
        if (openButton.matches('[data-catalog-map-open]')) {
            mapElement.dataset.latitude = openButton.dataset.latitude || '';
            mapElement.dataset.longitude = openButton.dataset.longitude || '';
            mapElement.dataset.title = openButton.dataset.title || 'Площадка';
            mapElement.dataset.address = openButton.dataset.address || '';
            if (title) title.textContent = mapElement.dataset.title;
            if (yandexMap) {
                yandexMap.destroy();
                yandexMap = null;
            }
        }
        window.setTimeout(loadMap, 0);
    }));

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

function initMiniGameScheduleControls() {
    document.querySelectorAll('[data-mini-game-schedule-toggle]').forEach((toggle) => {
        const form = toggle.closest('form');
        const fields = Array.from(form?.querySelectorAll('[data-mini-game-schedule-field]') || []);
        const inputs = Array.from(form?.querySelectorAll('[data-mini-game-schedule-input]') || []);

        if (!form || fields.length === 0 || inputs.length === 0) {
            return;
        }

        const synchronize = () => {
            const enabled = toggle.checked;

            fields.forEach((field) => {
                field.hidden = !enabled;
            });
            inputs.forEach((input) => {
                input.disabled = !enabled;

                if (!enabled) {
                    input.value = '';
                }
            });
        };

        toggle.addEventListener('change', synchronize);
        synchronize();
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

    const removeParticipant = (participant) => {
        section.querySelectorAll(`[data-mini-game-player-toggle][data-player-id="${participant.user_id}"]`)
            .forEach((toggle) => toggle.closest('.game-roster-toggle')?.remove());
        confirmedCount = Math.max(0, confirmedCount - 1);
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

    return { addParticipant, removeParticipant, setLoading };
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
    const participantsSurface = document.querySelector('[data-event-participants-surface]');
    const participantsOverlay = participantsSurface?.querySelector('[data-image-upload-overlay]');
    const confirmedCountLabel = document.querySelector('[data-event-confirmed-count]');

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

    const setParticipantsLoading = (loading) => {
        if (!participantsSurface) {
            return;
        }
        participantsSurface.classList.toggle('is-image-upload-loading', loading);
        participantsSurface.setAttribute('aria-busy', String(loading));
        if (participantsOverlay) {
            participantsOverlay.hidden = !loading;
        }
    };

    const group = (statusValue) => participantsSurface?.querySelector(`[data-event-participant-group="${statusValue}"]`);
    const updateGroupCount = (section) => {
        if (!section) {
            return;
        }
        const count = section.querySelectorAll('[data-event-participant-id]').length;
        const heading = section.querySelector('[data-event-participant-group-heading]');
        if (heading?.firstChild) {
            heading.firstChild.textContent = `\n                                ${heading.dataset.title} (${count})\n                                `;
        }
        section.hidden = count === 0;
    };
    const createParticipantCard = (participant, statusValue) => {
        const card = document.createElement('article');
        const avatar = document.createElement('div');
        const identity = document.createElement('div');
        const name = document.createElement('strong');
        const state = document.createElement('span');
        card.className = 'event-participant-chip';
        card.dataset.eventParticipantId = String(participant.id);
        avatar.className = 'event-person-avatar';
        identity.className = 'event-participant-chip__identity';
        if (participant.avatar_url) {
            const image = document.createElement('img');
            image.src = participant.avatar_url;
            image.alt = participant.name;
            avatar.append(image);
        } else {
            const initials = document.createElement('span');
            initials.textContent = participant.initials;
            avatar.append(initials);
        }
        name.textContent = participant.name;
        const statusLabels = { confirmed: 'Идёт', tentative: 'Думает', left: 'Не идёт' };
        state.textContent = statusLabels[statusValue] || '';
        identity.append(name, state);
        if (participant.changed_label && participant.changed_title) {
            const changed = document.createElement('small');
            changed.className = 'event-participant-chip__changed ui-tooltip-source ui-tooltip-source--title';
            changed.textContent = participant.changed_label;
            changed.dataset.tooltip = participant.changed_title;
            changed.dataset.tooltipSource = participant.changed_title;
            changed.tabIndex = 0;
            identity.append(changed);
        }
        card.append(avatar, identity);
        const actions = document.createElement('div');
        actions.className = 'event-participant-chip__status-actions';
        Object.entries(statusLabels).forEach(([targetStatus, targetLabel]) => {
            if (targetStatus === statusValue) {
                return;
            }
            const statusForm = document.createElement('form');
            const method = document.createElement('input');
            const csrf = document.createElement('input');
            const statusInput = document.createElement('input');
            const button = document.createElement('button');
            statusForm.method = 'POST';
            statusForm.action = participant.status_url;
            statusForm.dataset.eventParticipantStatusForm = '';
            statusForm.dataset.targetLabel = targetLabel;
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PATCH';
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
            statusInput.type = 'hidden';
            statusInput.name = 'status';
            statusInput.value = targetStatus;
            button.type = 'submit';
            button.className = 'btn btn--secondary event-participant-chip__status-button';
            button.textContent = targetLabel;
            statusForm.append(csrf, method, statusInput, button);
            actions.append(statusForm);
        });
        card.append(actions);
        return card;
    };
    const insertParticipant = (participant, statusValue) => {
        const target = group(statusValue);
        const row = target?.querySelector('.event-participants__row');
        if (!target || !row) {
            return;
        }
        target.hidden = false;
        target.querySelector(`[data-event-participant-id="${participant.id}"]`)?.remove();
        const card = createParticipantCard(participant, statusValue);
        const remaining = row.querySelector('.event-participant-chip--remaining');
        row.insertBefore(card, remaining);
        updateGroupCount(target);
    };
    const updateConfirmedCount = (count) => {
        if (!confirmedCountLabel) {
            return;
        }
        const maximum = participantsSurface?.dataset.maxParticipants;
        confirmedCountLabel.textContent = maximum ? `${count}/${maximum}` : String(count);
        if (maximum) {
            const remainingCard = group('confirmed')?.querySelector('.event-participant-chip--remaining:not(.event-participant-chip--unlimited)');
            const remaining = Math.max(0, Number.parseInt(maximum, 10) - count);
            if (remainingCard && remaining === 0) {
                remainingCard.remove();
            } else if (remainingCard) {
                const ending = remaining % 10 === 1 && remaining % 100 !== 11
                    ? 'место'
                    : ([2, 3, 4].includes(remaining % 10) && ![12, 13, 14].includes(remaining % 100) ? 'места' : 'мест');
                const label = remainingCard.querySelector('strong');
                if (label) {
                    label.textContent = `Ещё ${remaining} ${ending}`;
                }
            }
        }
    };

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
        setParticipantsLoading(true);

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

            insertParticipant(payload.participant, 'tentative');
            updateConfirmedCount(payload.confirmed_count);
            showStatus(payload.message || 'Пользователь добавлен в список «Думают».');
            reset();
        } catch (error) {
            showMessage(error?.message || 'Не удалось добавить участника.');
            submitButton.disabled = userId.value === '';
        } finally {
            setParticipantsLoading(false);
        }
    });

    participantsSurface?.addEventListener('submit', async (event) => {
        const statusForm = event.target instanceof Element
            ? event.target.closest('[data-event-participant-status-form]')
            : null;
        if (!(statusForm instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();
        if (!window.confirm(`Вы уверены, что хотите установить статус «${statusForm.dataset.targetLabel}»?`)) {
            return;
        }

        setParticipantsLoading(true);
        try {
            const response = await fetch(statusForm.action, {
                method: 'POST',
                body: new FormData(statusForm),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось подтвердить участие.');
            }
            const wasConfirmed = Boolean(group('confirmed')?.querySelector(`[data-event-participant-id="${payload.participant.id}"]`));
            ['confirmed', 'tentative', 'left', 'reconfirmation'].forEach((statusValue) => {
                const sourceGroup = group(statusValue);
                sourceGroup?.querySelector(`[data-event-participant-id="${payload.participant.id}"]`)?.remove();
                updateGroupCount(sourceGroup);
            });
            insertParticipant(payload.participant, payload.participant.status);
            updateConfirmedCount(payload.confirmed_count);
            if (payload.participant.status === 'confirmed' && !wasConfirmed) {
                miniGames?.addParticipant(payload.participant);
            } else if (payload.participant.status !== 'confirmed' && wasConfirmed) {
                miniGames?.removeParticipant(payload.participant);
            }
            showStatus(payload.message || 'Статус пользователя обновлён.');
        } catch (error) {
            showMessage(error?.message || 'Не удалось подтвердить участие.');
        } finally {
            setParticipantsLoading(false);
        }
    });
}
document.querySelectorAll('[data-event-filters]').forEach((filters) => {
    const panel = filters;
    const toggles = document.querySelectorAll('[data-event-filter-toggle]');
    const toolbar = filters.previousElementSibling?.classList.contains('events-catalog-filters__toolbar')
        ? filters.previousElementSibling
        : null;

    if (!panel) return;

    toggles.forEach((toggle) => toggle.addEventListener('click', () => {
        const willOpen = panel.hidden;
        panel.hidden = !willOpen;
        toolbar?.classList.toggle('is-filters-collapsed', !willOpen);
        toggles.forEach((item) => {
            item.setAttribute('aria-expanded', String(willOpen));
            const icon = item.querySelector('[data-event-filter-toggle-icon]');
            icon?.classList.toggle('ti-chevron-up', willOpen);
            icon?.classList.toggle('ti-chevron-down', !willOpen);
        });
    }));
});
