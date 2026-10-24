import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupTodayEvents, safeEventLink, venueIdFromLink, eventCardData } from '../../resources/js/page-builder-today-event-map.js';

globalThis.window = { location: { origin: 'https://dev.mskba.ru' } };

test('same venue has multiple events in its existing popup; unrelated venues do not', () => {
    const events = [
        { id: 11, venue_id: 1, title: 'Игра', starts_at: '18:00', url: '/events/11-igra' },
        { id: 12, venue_id: 1, title: 'Тренировка', starts_at: '17:00', url: '/events/12-training' },
        { id: 13, venue_id: 2, title: 'Другая', starts_at: '19:00', url: '/events/13-other' },
        { id: 14, venue_id: 3, title: 'Bad', starts_at: '19:00', url: 'javascript:alert(1)' },
    ];
    const groups = groupTodayEvents(events);
    assert.equal(groups.length, 3);
    assert.deepEqual(eventCardData(events, 1).map(x => x.id), [12, 11]);
    assert.deepEqual(eventCardData(events, 2).map(x => x.id), [13]);
    assert.equal(eventCardData(events, 3).length, 0);
    assert.equal(eventCardData(events, null).length, 0);
});

test('venue id comes from the actual public venue link, not from DOM text', () => {
    assert.equal(venueIdFromLink('https://dev.mskba.ru/venues/1-shkola-1794'), 1);
    assert.equal(venueIdFromLink('/venues/16'), 16);
    assert.equal(venueIdFromLink('/events/16'), null);
    assert.equal(venueIdFromLink('https://attacker.example/venues/1'), null);
});

test('event action accepts only same-origin event detail pages', () => {
    assert.equal(safeEventLink('/events/10-test'), '/events/10-test');
    assert.equal(safeEventLink('https://evil.example/events/10'), null);
    assert.equal(safeEventLink('javascript:alert(1)'), null);
    assert.equal(safeEventLink('/backoffice/events/10/edit'), null);
});
