/* One brand marker for individual venue locations across all maps.
 * Cluster icons are intentionally independent of this helper. */
import venueLogo from '../../../mskba_app/assets/images/logo-mark-100.png';
const SIZE=52;
export function venuePinOptions(){
    const half=SIZE/2;
    return {
        iconLayout:'default#image',
        iconImageHref:venueLogo,
        iconImageSize:[SIZE,SIZE],
        iconImageOffset:[-half,-half],
        iconShape:{type:'Rectangle',coordinates:[[-half,-half],[half,half]]},
        cursor:'pointer',
    };
}
export function venuePlacemark(coordinates,properties={}){
    return new window.ymaps.Placemark(coordinates,properties,venuePinOptions());
}
