import assert from 'node:assert/strict';
import test from 'node:test';
import { groupDayBookingSlots } from '../../resources/themes/mskba_app/js/venue-day-booking-groups.js';

const slot = (from, to, key = null, status = 'booked') => ({
    starts_at: from, ends_at: to, status,
    booking_group: key ? { key, has_event: false, event: null } : null,
});

test('one MSKBA booking spanning multiple steps becomes one card', () => {
    const items = groupDayBookingSlots([
        slot('07:00', '07:30', 'booking-a'),
        slot('07:30', '08:00', 'booking-a'),
        slot('08:00', '08:30', 'booking-a'),
    ]);
    assert.equal(items.length, 1);
    assert.equal(items[0].count, 3);
    assert.equal(items[0].first.starts_at, '07:00');
    assert.equal(items[0].last.ends_at, '08:30');
});

test('separate bookings and imported busy slots never join', () => {
    const items = groupDayBookingSlots([
        slot('07:00', '07:30', 'a'), slot('07:30', '08:00', 'b'),
        slot('08:00', '08:30'), slot('08:30', '09:00'),
        slot('09:00', '09:30', 'b'), slot('09:30', '10:00', 'b'),
    ]);
    assert.deepEqual(items.map(group => group.count), [1, 1, 1, 1, 2]);
});

test('gaps, midnight and available rows are preserved', () => {
    const items = groupDayBookingSlots([
        slot('22:00', '22:30', 'a'),
        slot('23:00', '23:30', 'a'),
        slot('23:30', '24:00', 'a'),
        slot('00:00', '00:30', null, 'available'),
    ]);
    assert.deepEqual(items.map(group => group.count), [1, 2, 1]);
});
