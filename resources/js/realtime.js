import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { resolveReverbBrowserConfig } from './reverb-browser-config.js';

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
    // The app theme supplies its own public Reverb key at runtime.
    // GitHub Actions must never bake local .env.example endpoints into dev bundles.
    const runtimeKeyMeta = document.querySelector('meta[name="mskba-reverb-app-key"]');
    const browserConfig = resolveReverbBrowserConfig(
        runtimeKeyMeta === null ? null : runtimeKeyMeta.content,
        {
            key: import.meta.env.VITE_REVERB_APP_KEY,
            host: import.meta.env.VITE_REVERB_PUBLIC_HOST,
            port: import.meta.env.VITE_REVERB_PUBLIC_PORT,
            scheme: import.meta.env.VITE_REVERB_PUBLIC_SCHEME,
        },
        window.location,
    );

    if (!browserConfig.key) {
        announceState('unavailable');
        return null;
    }

    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key: browserConfig.key,
        wsHost: browserConfig.host,
        wsPort: browserConfig.port,
        wssPort: browserConfig.port,
        forceTLS: browserConfig.forceTLS,
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

export const realtimeSocketId = () => echo?.socketId?.() || null;
