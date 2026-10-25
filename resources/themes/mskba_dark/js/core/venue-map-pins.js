/* Shared MSKBA venue placemark artwork and hit area (home, catalog, venue detail).
 * The interaction rectangle is the FULL visible marker, not just its center. */
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="52" height="62" viewBox="0 0 52 62">
<defs><filter id="s" x="-35%" y="-25%" width="170%" height="180%"><feDropShadow dx="0" dy="3" stdDeviation="2" flood-color="#182432" flood-opacity=".36"/></filter></defs>
<path d="M26 59C22 50 6 36 6 25 6 13.5 14.9 4.5 26 4.5s20 9 20 20.5C46 36 30 50 26 59Z" fill="#e86b0c" stroke="#212934" stroke-width="2.3" filter="url(#s)"/>
<circle cx="26" cy="24" r="14.5" fill="#f89427" stroke="#252c31" stroke-width="2"/>
<path d="M12 24h28M26 9.5v29M15 15c9 2 11 6 11 9s-2 8-11 9M37 15c-9 2-11 6-11 9s2 8 11 9" fill="none" stroke="#222" stroke-width="2.1" stroke-linecap="round"/>
</svg>`;
const iconUrl = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
export function venuePinOptions() {
    return {
        iconLayout: 'default#image',
        iconImageHref: iconUrl,
        iconImageSize: [52, 62],
        iconImageOffset: [-26, -62],
        iconShape: {type:'Rectangle',coordinates:[[-26,-62],[26,0]]},
        cursor: 'pointer',
    };
}
export function venuePlacemark(coordinates, properties = {}) {
    return new window.ymaps.Placemark(coordinates, properties, venuePinOptions());
}
