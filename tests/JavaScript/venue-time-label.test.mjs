import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { displayClock } from '../../resources/themes/mskba_app/js/venue-time-label.js';

test('end-of-day UI always uses 00:00 but preserves other clock labels', () => {
    assert.equal(displayClock('24:00'), '00:00');
    assert.equal(displayClock('23:59'), '00:00');
    assert.equal(displayClock('00:00'), '00:00');
    assert.equal(displayClock('23:30'), '23:30');
    assert.equal(displayClock('07:00'), '07:00');
});

test('all displayed calendar and slot clock labels go through displayClock', () => {
    const source = readFileSync(
        new URL('../../resources/themes/mskba_app/js/venue-detail.js', import.meta.url), 'utf8',
    );
    assert.match(source, /times\.textContent = displayClock\(slot\.starts_at\) \+ '–' \+ displayClock\(slot\.ends_at\)/);
    assert.match(source, /summary\.textContent = \[displayClock\(start\.starts_at\), '–', displayClock\(end\.ends_at\)/);
    assert.match(source, /displayClock\(response\.slots\[selection\.at\(-1\)\]\.ends_at\)/);
    assert.match(source, /hours\.textContent = displayClock\(data\.slots\[0\]\.starts_at\)/);
    // API, preview and submission must continue receiving original time.
    assert.match(source, /response\.date \+ 'T' \+ response\.slots\[selection\[0\]\]\.starts_at/);
});

test('both slot-picker templates stay compact and venue offer lives in About', () => {
    const show = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/show.blade.php', import.meta.url), 'utf8',
    );
    const quote = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/rental-quote.blade.php', import.meta.url), 'utf8',
    );
    const calendar = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/partials/day-calendar.blade.php', import.meta.url), 'utf8',
    );
    assert.match(show, /id="app-venue-about"[\s\S]*?app-venue-about__promotions/);
    assert.doesNotMatch(quote, /venueSlotPromotions|app-venue-day-dialog__promotion/);
    assert.doesNotMatch(show, /Рабочие часы площадки и исключения из обычного графика/);
    assert.doesNotMatch(show, /Часы работы не гарантируют доступность бронирования/);
    for (const markup of [show, quote]) {
        const dialog = markup.split('class="app-venue-overlay app-venue-day-dialog"')[1] || '';
        assert.doesNotMatch(dialog.split('</dialog>')[0], /app-venue-day-dialog__promotion|app-venue-day-dialog__info/);
    }
    assert.equal((calendar.match(/\['23:59', '24:00'\]/g) || []).length, 2);
});

test('legacy venue schedule normalizes displayed end-of-day while keeping raw interval attributes', () => {
    const legacy = readFileSync(
        new URL('../../resources/themes/mskba_dark/views/pages/venues/show.blade.php', import.meta.url), 'utf8',
    );
    const legacyJs = readFileSync(
        new URL('../../resources/themes/mskba_dark/js/features/venue-show.js', import.meta.url), 'utf8',
    );
    assert.equal((legacy.match(/in_array\(\$timelineItem\['endsAt'\], \['23:59', '24:00'\]/g) || []).length, 2);
    assert.match(legacy, /in_array\(\$interval\['endsAt'\], \['23:59', '24:00'\]/);
    assert.match(legacy, /data-end="{{ \$timelineItem\['endsAt'\] }}"/);
    assert.match(legacyJs, /\['23:59', '24:00'\]\.includes\(interval\.endsAt\)/);
});
