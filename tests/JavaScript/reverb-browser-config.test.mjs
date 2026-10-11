import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveReverbBrowserConfig } from '../../resources/js/reverb-browser-config.js';

const fallback = {
    key: 'mskba-local-key',
    host: 'localhost',
    port: '8000',
    scheme: 'http',
};

test('dev runtime ignores localhost build defaults and uses secure same-origin websocket', () => {
    const config = resolveReverbBrowserConfig(
        'dev-public-reverb-key',
        fallback,
        { protocol: 'https:', hostname: 'dev.mskba.ru', port: '' },
    );
    assert.deepEqual(config, {
        key: 'dev-public-reverb-key',
        host: 'dev.mskba.ru',
        port: 443,
        forceTLS: true,
    });
});

test('local runtime uses its own origin and port', () => {
    const config = resolveReverbBrowserConfig(
        'local-public-reverb-key',
        fallback,
        { protocol: 'http:', hostname: 'localhost', port: '8000' },
    );
    assert.equal(config.key, 'local-public-reverb-key');
    assert.equal(config.port, 8000);
    assert.equal(config.forceTLS, false);
});

test('legacy themes without runtime metadata keep their existing Vite configuration', () => {
    const config = resolveReverbBrowserConfig(
        null,
        fallback,
        { protocol: 'http:', hostname: 'localhost', port: '8000' },
    );
    assert.equal(config.key, 'mskba-local-key');
    assert.equal(config.host, 'localhost');
    assert.equal(config.port, 8000);
    assert.equal(config.forceTLS, false);
});

test('missing runtime key disables realtime instead of falling back to CI example key', () => {
    const config = resolveReverbBrowserConfig(
        '',
        fallback,
        { protocol: 'https:', hostname: 'dev.mskba.ru', port: '' },
    );
    assert.equal(config.key, '');
    assert.equal(config.host, 'dev.mskba.ru');
});
