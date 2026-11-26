// null means full reconciliation; [] means no data request is required.
export function affectedDashboardSources(event, visibleSources) {
    if (event?.catalog_changed || !Array.isArray(event?.sources)
        || event.sources.length === 0 || event.sources.some(key => typeof key !== 'string' || !key)) return null;
    const keys = new Set(event.sources);
    // A proposal can change the centre of unresolved actions as well as rentals.
    if (keys.has('my_rental_requests')) keys.add('needs_attention');
    return [...new Set(visibleSources)].filter(key => keys.has(key));
}

// Full refresh dominates any set of data-only requests.
export function mergeDashboardRefresh(current, incoming) {
    if (current === null || incoming === null) return null;
    return [...new Set([...current, ...incoming])];
}

export function sameDashboardIds(current, incoming) {
    if (!Array.isArray(incoming) || current.length !== incoming.length) return false;
    const ids = new Set(incoming.map(String));
    return ids.size === incoming.length && current.every(id => ids.has(String(id)));
}
