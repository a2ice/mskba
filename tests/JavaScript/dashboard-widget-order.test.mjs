import test from 'node:test';
import assert from 'node:assert/strict';
import { reorderDashboardWidgets } from '../../resources/themes/mskba_app/js/dashboard-widget-order.js';

test('can_move=false keeps a fixed widget at exactly the same grid slot', () => {
    const widgets = [
        { id: 11, canMove: true },
        { id: 12, canMove: false },
        { id: 13, canMove: true },
        { id: 14, canMove: true },
    ];
    assert.deepEqual(reorderDashboardWidgets(widgets, 11, 14), [13, 12, 14, 11]);
    assert.deepEqual(reorderDashboardWidgets(widgets, 14, 11), [14, 12, 11, 13]);
});

test('neither source nor target may be a locked widget', () => {
    const widgets = [
        { id: 11, canMove: true },
        { id: 12, canMove: false },
        { id: 13, canMove: true },
    ];
    assert.equal(reorderDashboardWidgets(widgets, 12, 13), null);
    assert.equal(reorderDashboardWidgets(widgets, 13, 12), null);
    assert.equal(reorderDashboardWidgets(widgets, 11, 11), null);
});

test('all movable widgets retain old drag/drop semantics', () => {
    const widgets = [
        { id: 1, canMove: true },
        { id: 2, canMove: true },
        { id: 3, canMove: true },
    ];
    assert.deepEqual(reorderDashboardWidgets(widgets, 3, 1), [3, 1, 2]);
});
