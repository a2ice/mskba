import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;
let state = 'idle';

const announceState = (nextState) => {
    const previousState = state;
    state = nextState;
    window.dispatchEvent(new CustomEvent('mskba:realtime-state', {
        detail: { state: nextState, previousState },
    }));
};

const createEcho = () => {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        announceState('unavailable');
        return null;
    }

    window.Pusher = Pusher;
    const forceTLS = (import.meta.env.VITE_REVERB_PUBLIC_SCHEME || window.location.protocol.replace(':', '')) === 'https';
    const browserPort = Number(window.location.port || (forceTLS ? 443 : 80));

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_PUBLIC_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PUBLIC_PORT || browserPort),
        wssPort: Number(import.meta.env.VITE_REVERB_PUBLIC_PORT || browserPort),
        forceTLS,
        enabledTransports: ['ws', 'wss'],
    });

    const connection = echo.connector?.pusher?.connection;
    connection?.bind('connecting', () => announceState('connecting'));
    connection?.bind('connected', () => announceState('connected'));
    connection?.bind('unavailable', () => announceState('unavailable'));
    connection?.bind('disconnected', () => announceState('disconnected'));
    connection?.bind('failed', () => announceState('failed'));
    announceState('connecting');

    return echo;
};

export const subscribePublic = (channelName, eventName, handler) => {
    const client = echo || createEcho();

    if (!client) {
        return () => {};
    }

    client.channel(channelName).listen(eventName, handler);

    return () => {
        client.channel(channelName).stopListening(eventName, handler);
        client.leave(channelName);
    };
};

export const subscribePrivate = (channelName, eventName, handler) => {
    const client = echo || createEcho();

    if (!client) {
        return () => {};
    }

    client.private(channelName).listen(eventName, handler);

    return () => {
        client.private(channelName).stopListening(eventName, handler);
        client.leave(channelName);
    };
};

export const realtimeState = () => state;
