import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-detail.js', import.meta.url),
    'utf8',
);
const marker = "const dayBookingDialog = document.querySelector('[data-venue-day-dialog]');";
const start = source.indexOf(marker);
assert.ok(start >= 0, 'Daily booking module must exist in venue-detail.js');
const dayBookingModule = source.slice(start);

function node() {
    return {
        dataset: {},
        children: [],
        listeners: {},
        style: {},
        open: false,
        disabled: false,
        hidden: false,
        textContent: '',
        classList: { toggle() {} },
        addEventListener(type, listener) { this.listeners[type] = listener; },
        querySelectorAll() { return []; },
        querySelector() { return null; },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        setAttribute() {},
        removeAttribute() {},
        showModal() { this.open = true; },
        close() {
            this.open = false;
            this.listeners.close?.();
        },
    };
}

function mountDayBooking({ previewFails = false } = {}) {
    const status = node();
    const heading = node();
    const grid = node();
    const bookingButton = node();
    const summary = node();
    const minimum = node();
    const inputs = {
        '[data-venue-day-court]': node(),
        '[data-venue-day-start]': node(),
        '[data-venue-day-duration]': node(),
    };
    const form = node();
    form.querySelector = selector => inputs[selector] || null;
    grid.querySelectorAll = selector =>
        selector === 'button[data-slot-index]' ? grid.children : [];

    const dialog = node();
    dialog.dataset = {
        dayUrl: 'https://dev.mskba.ru/venues/26/schedule/day',
        courtId: '13',
    };
    const fields = {
        '[data-venue-day-status]': status,
        '[data-venue-day-title]': heading,
        '[data-venue-day-slots]': grid,
        '[data-venue-day-booking-form]': form,
        '[data-venue-day-book]': bookingButton,
        '[data-venue-day-summary]': summary,
        '[data-venue-day-minimum]': minimum,
    };
    dialog.querySelector = selector => fields[selector] || null;

    const opener = node();
    opener.dataset.dayDate = '2026-11-15';
    opener.querySelector = () => ({ textContent: '15 ноя' });
    const requests = [];
    const slots = [
        { starts_at: '21:00', ends_at: '21:30', selectable: true, status: 'available', price_minor: 357500 },
        { starts_at: '21:30', ends_at: '22:00', selectable: true, status: 'available', price_minor: 357500 },
    ];
    const fetch = async rawUrl => {
        const url = new URL(rawUrl);
        requests.push(url);
        if (url.searchParams.has('preview_start')) {
            if (previewFails) {
                return { ok: false, json: async () => ({ message: 'Стоимость временно недоступна' }) };
            }
            return { ok: true, json: async () => ({
                price_preview: { total_minor: 715000 },
            }) };
        }
        return { ok: true, json: async () => ({
            date: '2026-11-15',
            venue_court_id: 13,
            slots,
            booking_enabled: true,
            step_minutes: 30,
            minimum_minutes: 60,
            maximum_minutes: 180,
            refresh_state: 'cached',
        }) };
    };
    const document = {
        querySelector: selector => selector === '[data-venue-day-dialog]' ? dialog : null,
        querySelectorAll: selector => selector === '[data-venue-day-open]' ? [opener] : [],
        createElement: node,
    };
    runInNewContext(dayBookingModule, {
        document,
        window: { location: { href: 'https://dev.mskba.ru/venues/26-basketpoint-na-dubinskoy' } },
        AbortController, URL, fetch,
    }, { filename: 'venue-detail.js' });
    return {
        dialog, opener, grid, bookingButton, summary, minimum, inputs, requests,
        async open() {
            await opener.listeners.click();
            assert.equal(grid.children.length, 2);
        },
        clickSlot(index) {
            grid.listeners.click({
                target: { closest: selector =>
                    selector === 'button[data-slot-index]' ? grid.children[index] : null },
            });
        },
    };
}

for (const audience of ['авторизованный пользователь', 'гость']) {
    test(`два выбранных слота запускают расчёт и разблокируют бронирование: ${audience}`, async () => {
        const page = mountDayBooking();
        await page.open();
        page.clickSlot(0);
        assert.equal(page.bookingButton.disabled, true, 'Минимум 60 минут, один слот не подходит');
        page.clickSlot(1);
        await new Promise(resolve => setImmediate(resolve));

        assert.equal(page.requests.length, 2, 'Расчёт должен отправляться через GET day API');
        assert.equal(page.requests[1].searchParams.get('preview_start'), '21:00');
        assert.equal(page.requests[1].searchParams.get('preview_duration_minutes'), '60');
        assert.equal(page.bookingButton.disabled, false);
        assert.match(page.summary.textContent, /7\s*150/);
        assert.equal(page.inputs['[data-venue-day-court]'].value, '13');
        assert.equal(page.inputs['[data-venue-day-start]'].value, '2026-11-15T21:00');
        assert.equal(page.inputs['[data-venue-day-duration]'].value, '60');
    });
}

test('при ошибке расчёта показывается объяснение вместо бесконечного ожидания', async () => {
    const page = mountDayBooking({ previewFails: true });
    await page.open();
    page.clickSlot(0);
    page.clickSlot(1);
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(page.bookingButton.disabled, true);
    assert.match(page.summary.textContent, /Стоимость временно недоступна/);
});
