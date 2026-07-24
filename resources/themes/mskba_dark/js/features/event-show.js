import { loadYandexMaps } from '../core/yandex-maps.js';

document.addEventListener('DOMContentLoaded', () => {
    initEventHero();
    initEventVenueMap();
    initEventDescription();
    initEventShare();
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
