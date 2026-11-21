// Shared small venue balloon action. No user-provided HTML.
export function venueMapBalloonAction(value, visible = true, origin = location.origin) {
    if (!visible || !value) return '';
    let url;
    try { url = new URL(String(value), origin); } catch { return ''; }
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin ||
        !/^\/venues\/[^/]+\/?$/.test(url.pathname)) return '';
    const a = document.createElement('a');
    a.href = url.href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.className = 'mskba-venue-map-balloon-action';
    a.textContent = 'К площадке';
    return a.outerHTML;
}
