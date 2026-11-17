// A booking may span several calendar steps; never join unrelated busy slots.
// A missing key means external/unidentified occupancy and stays individual.
export function groupDayBookingSlots(slots) {
    const groups = [];
    for (const [index, slot] of slots.entries()) {
        const previous = groups.at(-1);
        const key = slot.status === 'booked' ? slot.booking_group?.key : null;
        if (key && previous?.bookingKey === key
            && previous.last.ends_at === slot.starts_at) {
            previous.last = slot;
            previous.lastIndex = index;
            previous.count += 1;
        } else {
            groups.push({
                first: slot, last: slot, firstIndex: index, lastIndex: index,
                count: 1, bookingKey: key || null,
            });
        }
    }
    return groups;
}
