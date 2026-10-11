import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_VISIBLE_TOASTS,
    createNotificationToastDock,
    truncateToastMessage,
} from '../../resources/themes/mskba_app/js/notification-toasts.js';

class FakeElement {
    constructor(tag = 'div') {
        this.tag = tag;
        this.className = '';
        this.dataset = {};
        this.children = [];
        this.attributes = {};
        this.listeners = {};
        this.parent = null;
        this.hidden = false;
        this.disabled = false;
        this.style = {
            values: {},
            setProperty: (key, value) => { this.style.values[key] = value; },
        };
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    append(...children) {
        children.forEach((child) => {
            child.remove();
            child.parent = this;
            this.children.push(child);
        });
    }

    prepend(...children) {
        children.forEach((child) => {
            child.remove();
            child.parent = this;
        });
        this.children.unshift(...children);
    }

    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((item) => item !== this);
            this.parent = null;
        }
    }

    querySelector(selector) {
        const className = selector.startsWith('.') ? selector.slice(1) : selector;
        for (const child of this.children) {
            if (child.className.split(' ').includes(className)) return child;
            const nested = child.querySelector(selector);
            if (nested) return nested;
        }
        return null;
    }

    addEventListener(name, callback) {
        this.listeners[name] = callback;
    }

    trigger(name, options = {}) {
        const event = {
            button: 0,
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            ...options,
        };
        return this.listeners[name]?.(event);
    }
}

const notification = (id, overrides = {}) => ({
    id,
    title: 'Добро пожаловать в MSKBA',
    body: 'Короткий текст',
    type: 'system',
    type_color: '#ff8a00',
    href: '/faq/welcome',
    action_text: 'Подробнее о первых шагах',
    read_url: '/account/notifications/' + id + '/read',
    ...overrides,
});

const setup = () => {
    const region = new FakeElement();
    region.hidden = true;
    const counts = [];
    const visits = [];
    const requests = [];
    let response = { ok: true, json: async () => ({ unread_count: 2 }) };

    globalThis.document = {
        querySelector: () => ({ content: 'csrf-token' }),
        createElement: (tag) => new FakeElement(tag),
    };
    globalThis.window = {
        location: {
            origin: 'https://dev.mskba.ru',
            assign: (url) => visits.push(url),
        },
    };
    globalThis.fetch = async (url, options) => {
        requests.push({ url, options });
        return response;
    };

    const dock = createNotificationToastDock(region, (count) => counts.push(count));
    return {
        region, dock, counts, visits, requests,
        respondWith: (next) => { response = next; },
    };
};

test('shortens the body to at most 50 characters with three dots', () => {
    const fifty = 'а'.repeat(50);
    assert.equal(truncateToastMessage(fifty), fifty);
    assert.equal(truncateToastMessage(fifty + 'б'), 'а'.repeat(47) + '...');
    assert.equal([...truncateToastMessage('😀'.repeat(51))].length, 50);
    assert.equal(truncateToastMessage('  текст  '), 'текст');

    const { dock, region } = setup();
    dock.add(notification(1, { body: fifty + 'б' }));
    const body = region.children[0].querySelector('.app-message-toast__body');
    assert.equal(body.textContent, 'а'.repeat(47) + '...');
    assert.equal(body.attributes['aria-label'], fifty + 'б');
    assert.equal(body.title, fifty + 'б');
});

test('displays only three items and fills the freed slot without reading pending items', async () => {
    const { dock, region, counts, requests } = setup();
    dock.reconcile([notification(4), notification(3), notification(2), notification(1)]);

    assert.equal(MAX_VISIBLE_TOASTS, 3);
    assert.equal(dock.size(), 3);
    assert.deepEqual(region.children.map((item) => item.dataset.messageToastId), ['4', '3', '2']);
    assert.equal(requests.length, 0);

    await region.children[0].querySelector('.app-message-toast__close').trigger('click');
    assert.deepEqual(region.children.map((item) => item.dataset.messageToastId), ['3', '2', '1']);
    assert.deepEqual(counts, [2]);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.method, 'PATCH');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');

    dock.reconcile([notification(3), notification(2), notification(1)]);
    assert.equal(dock.size(), 3);
});

test('clicking an action marks it read before navigating', async () => {
    const { dock, region, requests, visits, counts } = setup();
    dock.add(notification(8));
    const link = region.children[0].querySelector('.app-message-toast__link');
    assert.ok(link);
    await link.trigger('click');

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/account/notifications/8/read');
    assert.deepEqual(visits, ['https://dev.mskba.ru/faq/welcome']);
    assert.deepEqual(counts, [2]);
    assert.equal(dock.size(), 0);
    assert.equal(region.hidden, true);
});

test('failed read keeps the toast visible, shows an error and does not navigate', async () => {
    const { dock, region, visits, respondWith } = setup();
    respondWith({ ok: false });
    dock.add(notification(9));
    await region.children[0].querySelector('.app-message-toast__link').trigger('click');
    assert.equal(dock.size(), 1);
    assert.equal(region.hidden, false);
    assert.deepEqual(visits, []);
    assert.match(region.children[0].querySelector('.app-message-toast__error').textContent, /Не удалось/);
});

test('modifier-click is a normal browser link; only server-provided safe colors and URLs are used', async () => {
    const { dock, region, requests } = setup();
    dock.add(notification(10, { type: 'message', type_color: '#38bdf8' }));
    const card = region.children[0];
    assert.equal(card.style.values['--toast-accent'], '#38bdf8');
    await card.querySelector('.app-message-toast__link').trigger('click', { ctrlKey: true });
    assert.equal(requests.length, 0);

    dock.add(notification(11, { type_color: 'url(https://evil.test)', href: 'javascript:alert(1)' }));
    const second = region.children[1];
    assert.equal(second.style.values['--toast-accent'], undefined);
    assert.equal(second.querySelector('.app-message-toast__link'), null);
});

test('reconciliation heals cross-tab reads and makes a waiting toast visible', () => {
    const { dock, region } = setup();
    dock.reconcile([1, 2, 3, 4].map((id) => notification(id)));
    dock.reconcile([notification(2), notification(3), notification(4)]);
    assert.deepEqual(region.children.map((item) => item.dataset.messageToastId), ['2', '3', '4']);
});
