// Public clock notation. Keep 24:00 as an internal exclusive end-of-day
// marker in API/availability math, but never expose it as a UI time.
export function displayClock(time) {
    return time === '24:00' || time === '23:59' ? '00:00' : time;
}
