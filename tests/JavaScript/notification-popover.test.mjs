import test from 'node:test';
import assert from 'node:assert/strict';
import {
    conversationFromHref, isMessagesPage, isVisibleCurrentThreadToast,
} from '../../resources/themes/mskba_app/js/notification-popover.js';

const origin = 'https://dev.mskba.ru';

test('detects support and direct threads only on the current origin', () => {
    assert.deepEqual(conversationFromHref('/account/notifications/threads/support/17', origin),
        { kind: 'support', id: 17 });
    assert.deepEqual(conversationFromHref(origin + '/account/notifications/threads/direct/23', origin),
        { kind: 'direct', id: 23 });
    assert.deepEqual(conversationFromHref('/account/notifications/threads/support/5/', origin),
        { kind: 'support', id: 5 });
});

test('rejects off-origin, protocols, fragments, params and nonchat routes', () => {
    for (const candidate of [
        'https://evil.example/account/notifications/threads/direct/1',
        '//evil.example/account/notifications/threads/support/1',
        'javascript:alert(1)',
        '/account/notifications/threads/support/01',
        '/account/notifications/threads/support/-1',
        '/account/notifications/threads/support/99?token=sensitive',
        '/account/notifications/threads/direct/12#fragment',
        '/account/notifications/threads/profile/11',
        '/account/notifications/threads/system',
        '/faq',
        'not-a-real-url-path',
    ]) assert.equal(conversationFromHref(candidate, origin), null, candidate);
});

test('message section pages never show a competing draggable chat', () => {
    for (const path of [
        '/account/notifications', '/account/notifications/threads/direct/1',
        '/account/notifications/threads/support/17', '/account/notifications/support/start',
    ]) assert.equal(isMessagesPage(path), true, path);
    for (const path of ['/faq', '/', '/account', '/account/confirmation', '/games']) {
        assert.equal(isMessagesPage(path), false, path);
    }
});

test('active focused conversation suppresses only its own toast when at latest message', () => {
    const current = '/account/notifications/threads/support/17';
    assert.equal(isVisibleCurrentThreadToast({ href: current }, current, origin, true), true);
    assert.equal(isVisibleCurrentThreadToast({ href: current }, current, origin, false), false);
    assert.equal(isVisibleCurrentThreadToast(
        { href: '/account/notifications/threads/support/18' }, current, origin, true), false);
    assert.equal(isVisibleCurrentThreadToast(
        { href: 'https://evil.example/account/notifications/threads/support/17' }, current, origin, true), false);
    assert.equal(isVisibleCurrentThreadToast({ href: current }, '/faq', origin, true), false);
});
