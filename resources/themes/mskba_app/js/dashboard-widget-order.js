/**
 * Reorder only administrator-approved widget slots. Fixed widgets remain
 * at their exact indexes, even when movable cards cross them.
 *
 * @param {Array<{id: number, canMove: boolean}>} widgets
 * @param {number} sourceId
 * @param {number} targetId
 * @returns {number[] | null} New full order, or null for prohibited actions.
 */
export function reorderDashboardWidgets(widgets, sourceId, targetId) {
    const movable = widgets.filter((widget) => widget.canMove);
    const from = movable.findIndex((widget) => widget.id === sourceId);
    const to = movable.findIndex((widget) => widget.id === targetId);
    if (from < 0 || to < 0 || from === to) return null;

    const [selected] = movable.splice(from, 1);
    movable.splice(to, 0, selected);

    let next = 0;
    return widgets.map((widget) => widget.canMove ? movable[next++].id : widget.id);
}
