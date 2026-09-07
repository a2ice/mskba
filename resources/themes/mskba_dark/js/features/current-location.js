export async function reverseGeocode(url, latitude, longitude) {
    if (!url) {
        return null;
    }

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ latitude, longitude }),
    });

    if (!response.ok) {
        return null;
    }

    const data = await response.json();
    return data?.suggestion || null;
}

export function getCurrentCoordinates() {
    const telegram = window.Telegram?.WebApp;
    const locationManager = telegram?.LocationManager;

    if (locationManager && telegram.isVersionAtLeast?.('8.0')) {
        if (locationManager.isInited) {
            return requestTelegramLocation(locationManager);
        }

        return new Promise((resolve, reject) => {
            locationManager.init(() => {
                if (!locationManager.isLocationAvailable) {
                    reject(locationError('unavailable'));
                    return;
                }

                requestTelegramLocation(locationManager).then(resolve, reject);
            });
        });
    }

    if (!navigator.geolocation) {
        return Promise.reject(locationError('unavailable'));
    }

    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            (position) => resolve({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
            }),
            (error) => reject(locationError(
                error.code === error.PERMISSION_DENIED ? 'permission_denied' : 'unavailable',
            )),
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 },
        );
    });
}

function requestTelegramLocation(locationManager) {
    return new Promise((resolve, reject) => {
        locationManager.getLocation((location) => {
            if (!location) {
                reject(locationError('permission_denied'));
                return;
            }

            resolve({ latitude: location.latitude, longitude: location.longitude });
        });
    });
}

function locationError(code) {
    const error = new Error(code);
    error.code = code;
    return error;
}
