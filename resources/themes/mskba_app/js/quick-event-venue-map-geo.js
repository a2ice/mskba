// Pure map-viewport helper. Browser position remains client-side and is
// never included in backend venue-search requests.
export const NEAREST_VENUE_COUNT = 5;
// Standard MSKBA pin extends ~28px beyond its coordinate; keep another
// ~20px of visible breathing room from each edge.
export const NEARBY_ZOOM_MARGIN_PX = 48;

export function isValidMapPoint(point) {
    const lat = Number(point?.latitude);
    const lon = Number(point?.longitude);
    return point?.latitude != null && point?.longitude != null
        && Number.isFinite(lat) && Number.isFinite(lon)
        && Math.abs(lat) <= 90 && Math.abs(lon) <= 180
        && !(lat === 0 && lon === 0);
}

function distanceKm(user, venue) {
    const toRadians = value => Number(value) * Math.PI / 180;
    const latitude = toRadians(Number(venue.latitude) - Number(user.latitude));
    const longitude = toRadians(Number(venue.longitude) - Number(user.longitude));
    const half = Math.sin(latitude / 2) ** 2
        + Math.cos(toRadians(user.latitude))
        * Math.cos(toRadians(venue.latitude))
        * Math.sin(longitude / 2) ** 2;
    return 12742 * Math.asin(Math.sqrt(Math.min(1, half)));
}

// Make the viewport symmetric about the user's point and large enough for
// their nearest venues. Yandex zoomMargin adds an additional pixel gutter.
export function nearbyVenueBounds(user, venues, count = NEAREST_VENUE_COUNT) {
    if (!isValidMapPoint(user)) return null;
    const closest = (Array.isArray(venues) ? venues : [])
        .filter(isValidMapPoint)
        .map(venue => ({venue, distance: distanceKm(user, venue)}))
        .sort((a, b) => a.distance - b.distance)
        .slice(0, Math.max(1, count))
        .map(({venue}) => venue);

    if (!closest.length) return null;

    const lat = Number(user.latitude);
    const lon = Number(user.longitude);
    // 0.002° (~200m) avoids zooming to street level when marker positions
    // coincide or the venue list only contains one very close point.
    const latitudeRadius = Math.max(0.002,
        ...closest.map(v => Math.abs(Number(v.latitude) - lat)));
    const longitudeRadius = Math.max(0.002,
        ...closest.map(v => Math.abs(Number(v.longitude) - lon)));

    return {
        nearest: closest,
        bounds: [
            [Math.max(-90, lat - latitudeRadius), Math.max(-180, lon - longitudeRadius)],
            [Math.min(90, lat + latitudeRadius), Math.min(180, lon + longitudeRadius)],
        ],
    };
}
