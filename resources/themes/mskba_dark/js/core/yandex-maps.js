export function loadYandexMaps(apiKey) {
    if (window.ymaps) {
        return Promise.resolve();
    }

    if (window.mskbaYandexMapsLoading) {
        return window.mskbaYandexMapsLoading;
    }

    window.mskbaYandexMapsLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const params = new URLSearchParams({
            apikey: apiKey,
            lang: 'ru_RU',
        });

        script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`;
        script.async = true;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return window.mskbaYandexMapsLoading;
}
