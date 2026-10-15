import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationToastDock } from '../../resources/themes/mskba_app/js/notification-toasts.js';

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.listeners = {};
        this.hidden = false;
        this.disabled = false;
        this.parent = null;
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    append(...children) {
        for (const child of children) {
            child.parent = this;
            this.children.push(child);
        }
    }
    prepend(child) { child.parent = this; this.children.unshift(child); }
    remove() {
        if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1);
        this.parent = null;
    }
    querySelector(selector) {
        if (selector !== '.app-message-toast__error') return null;
        return this.children.find((child) => child.className === 'app-message-toast__error') || null;
    }
}

globalThis.document = {
    createElement: (name) => new FakeElement(name),
    querySelector: () => ({ content: 'test-csrf-token' }),
};
globalThis.window = { location: { origin: 'https://dev.mskba.ru' } };

const notification = (id) => ({
    id,
    read_url: `/account/notifications/${id}/read`,
    title: `Сообщение ${id}`,
    body: 'Проверка',
    href: '/account/notifications',
});

test('only three visible toasts, waiting queue and no read on dismiss', () => {
    const region = new FakeElement('section');
    region.hidden = true;
    const dock = createNotificationToastDock(region, () => {});
    const batch = Array.from({ length: 23 }, (_, index) => notification(index + 1));
    dock.reconcile(batch);
    assert.equal(dock.size(), 3);
    assert.equal(region.children.length, 3);
    dock.reconcile(batch);
    assert.equal(region.children.length, 3);

    region.children[0].children[1].listeners.click();
    assert.equal(dock.size(), 3);
    assert.deepEqual(region.children.map((node) => node.dataset.messageToastId), ['2','3','4']);
    dock.reconcile([]);
    assert.equal(dock.size(), 0);
    assert.equal(region.hidden, true);
});

test('explicit read marks message read and updates count without coupling to close', async () => {
    const previousFetch = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });
        return { ok: true, json: async () => ({ unread_count: 1 }) };
    };
    try {
        const region = new FakeElement('section');
        const counts = [];
        const dock = createNotificationToastDock(region, (count) => counts.push(count));
        dock.reconcile([notification(1), notification(2)]);
        const read = region.children[0].children.find(el => el.className === 'app-message-toast__read');
        await read.listeners.click();
        assert.equal(dock.size(), 1);
        assert.deepEqual(counts, [1]);
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, '/account/notifications/1/read');
        assert.equal(calls[0].options.method, 'PATCH');
        assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'test-csrf-token');
    } finally { globalThis.fetch = previousFetch; }
});

test('failed read does not discard unread toast or reduce the count', async () => {
    const previousFetch = globalThis.fetch;
    globalThis.fetch = async () => ({ ok: false });
    try {
        const region = new FakeElement('section');
        const counts = [];
        const dock = createNotificationToastDock(region, (count) => counts.push(count));
        dock.add(notification(8));
        await region.children[0].children.find(el => el.className === 'app-message-toast__read').listeners.click();
        assert.equal(dock.size(), 1);
        assert.deepEqual(counts, []);
        assert.equal(region.children[0].querySelector('.app-message-toast__error')?.textContent,
            'Не удалось отметить сообщение прочитанным.');
    } finally { globalThis.fetch = previousFetch; }
});
