// A server-rendered public key takes precedence over Vite build-time variables.
// This prevents shared CI artifacts from contacting localhost from the dev origin.
export const resolveReverbBrowserConfig = (runtimeKey, fallback, location) => {
    const hasRuntimeKey = runtimeKey !== null;
    const forceTLS = (hasRuntimeKey
        ? location.protocol.replace(':', '')
        : (fallback.scheme || location.protocol.replace(':', ''))) === 'https';
    const defaultPort = forceTLS ? 443 : 80;
    const pagePort = Number(location.port || defaultPort);

    return {
        key: hasRuntimeKey ? runtimeKey : (fallback.key || ''),
        host: hasRuntimeKey ? location.hostname : (fallback.host || location.hostname),
        port: hasRuntimeKey ? pagePort : Number(fallback.port || pagePort),
        forceTLS,
    };
};
