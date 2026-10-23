import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupTodayEvents, safeEventLink } from '../../resources/js/page-builder-today-event-map.js';

test('today event marker groups multiple events at the same venue', () => {
    const groups = groupTodayEvents([
        { id: 11, venue_id: 1, latitude: 55.82, longitude: 37.45, title: 'Игра' },
        { id: 12, venue_id: 1, latitude: 55.82, longitude: 37.45, title: 'Тренировка' },
        { id: 13, venue_id: 2, latitude: 55.7, longitude: 37.6, title: 'Другая' },
        { id: 14, venue_id: 3, latitude: null, longitude: 37.6, title: 'Без координат' },
    ]);
    assert.equal(groups.length, 2);
    assert.deepEqual(groups[0].events.map((event) => event.id), [11, 12]);
    assert.equal(groups[1].events[0].id, 13);
});

test('only local canonical event routes may appear in map balloons', () => {
    globalThis.window = { location: { origin: 'https://dev.mskba.ru' } };
    assert.equal(safeEventLink('/events/10-test'), '/events/10-test');
    assert.equal(safeEventLink('https://evil.example/events/10'), null);
    assert.equal(safeEventLink('javascript:alert(1)'), null);
    assert.equal(safeEventLink('/backoffice/events/10/edit'), null);
});
