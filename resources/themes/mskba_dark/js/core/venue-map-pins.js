/* The canonical MSKBA venue pin for EVERY individual venue on Yandex maps.
 * Clusterer styling/counts stay independent. Never use provider sport presets for venues. */
import brandMark from '../../../mskba_app/assets/images/logo-mark-100.png?inline';

const PIN_WIDTH = 56;
const PIN_HEIGHT = 70;
// Keep the real MSKBA mark inside the orange location pin shown on the main map.
// Explicitly inline the PNG so the logo also renders when SVG is used as an image.
const pinSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="56" height="70" viewBox="0 0 56 70">
<defs><filter id="shadow" x="-35%" y="-25%" width="170%" height="180%"><feDropShadow dx="0" dy="2.5" stdDeviation="2" flood-color="#182432" flood-opacity=".4"/></filter></defs>
<path d="M28 67C24 59 5 40 5 27 5 14 15 4 28 4S51 14 51 27C51 40 32 59 28 67Z"
 fill="#f37b0e" stroke="#252b34" stroke-width="2.1" filter="url(#shadow)"/>
<image x="10" y="10" width="36" height="37" href="${brandMark}" />
</svg>`;
const pinImage = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(pinSvg);

export function venuePinOptions() {
    return {
        iconLayout: 'default#image',
        iconImageHref: pinImage,
        iconImageSize: [PIN_WIDTH, PIN_HEIGHT],
        iconImageOffset: [-PIN_WIDTH / 2, -PIN_HEIGHT],
        // Rectangle encloses the whole visible graphic so ALL of the logo responds to clicks.
        iconShape: {
            type: 'Rectangle',
            coordinates: [[-PIN_WIDTH / 2, -PIN_HEIGHT], [PIN_WIDTH / 2, 0]],
        },
        cursor: 'pointer',
    };
}

export function venuePlacemark(coordinates, properties = {}) {
    return new window.ymaps.Placemark(coordinates, properties, venuePinOptions());
}
