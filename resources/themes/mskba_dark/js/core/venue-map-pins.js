/* Canonical individual-venue placemark used throughout MSKBA.
 * Reuses the homepage Page Builder's .mskba-venues-map__pin DOM and CSS.
 * Do NOT apply this to clusters: their separate Yandex layouts show counts. */
let sharedLayout;
export function venuePinOptions() {
    sharedLayout ||= window.ymaps.templateLayoutFactory.createClass(
        '<div class="mskba-venues-map__pin"><span></span></div>',
    );
    return {
        iconLayout: sharedLayout,
        iconOffset: [-20, -20],
        // The CSS-rotated 38px pin occupies ~54px. Include its entire visual,
        // especially the outer edge of the MSKBA logo, in the hit target.
        iconShape: { type: 'Rectangle', coordinates: [[-28, -28], [28, 30]] },
        hideIconOnBalloonOpen: false,
        cursor: 'pointer',
    };
}
export function venuePlacemark(coordinates, properties = {}) {
    return new window.ymaps.Placemark(coordinates, properties, venuePinOptions());
}
