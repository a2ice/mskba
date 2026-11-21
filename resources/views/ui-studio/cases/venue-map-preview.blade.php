{{-- Reuse the production shell; only the map provider is replaced in the offline UI Studio. --}}
{!! \Illuminate\Support\Facades\View::file(resource_path('themes/mskba_app/views/partials/venue-map-dialog.blade.php'), ['mapPreview'=>$mapPreview,'mapShowCurrentVenueLink'=>$mapShowCurrentVenueLink])->render() !!}
<style>
[data-map-preview] .studio-map-canvas{position:relative;height:350px;overflow:hidden;background:#f3f0e9;color:#263b51;isolation:isolate}
[data-map-preview] .studio-map-land{position:absolute;inset:0;width:100%;height:100%;display:block}
[data-map-preview] .studio-map-pin-button{position:absolute;left:58%;top:51%;width:56px;height:56px;transform:translate(-50%,-50%);display:grid;place-items:center;border:0;background:none;cursor:pointer;z-index:2}
[data-map-preview] .studio-map-pin-button[data-map-nearby-pin]{left:80%;top:72%}
[data-map-preview] .studio-map-pin-button .mskba-venues-map__pin{pointer-events:none}
[data-map-preview] .studio-map-pin-button .mskba-venues-map__pin span{background:none;display:grid;place-items:center;font-size:22px}
[data-map-preview] .studio-map-balloon{position:absolute;z-index:4;left:58%;top:calc(51% - 38px);transform:translate(-50%,-100%);width:max-content;max-width:min(250px,85%);padding:13px;background:#fff;color:#17212b;border-radius:7px;box-shadow:0 3px 14px #0003;text-align:left}
[data-map-preview] .studio-map-balloon.is-nearby{left:75%;top:calc(72% - 38px)}
[data-map-preview] .studio-map-balloon::after{content:"";position:absolute;left:50%;bottom:-9px;border:10px solid transparent;border-top-color:#fff;border-bottom:0;transform:translateX(-50%)}
[data-map-preview] .studio-map-balloon-head{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700}
[data-map-preview] .studio-map-balloon-head button{display:grid;place-items:center;width:28px;height:28px;border:0;background:transparent;color:#69737e;cursor:pointer;font-size:22px}
[data-map-preview] .studio-map-label{position:absolute;bottom:5px;right:7px;color:#52606c;background:#fff9;padding:2px 5px;font-size:10px}
[data-map-preview] .studio-map-controls{position:absolute;left:10px;top:32%;display:grid;box-shadow:0 2px 6px #0002;border-radius:4px;overflow:hidden}
[data-map-preview] .studio-map-controls button{width:32px;height:32px;display:grid;place-items:center;background:#fff;border:0;color:#59626c;font-size:20px;cursor:pointer}
[data-map-preview] .studio-map-controls button+button{border-top:1px solid #ddd}
[data-map-preview] .studio-map-canvas [hidden]{display:none!important}
@media(max-width:640px){[data-map-preview] .studio-map-canvas{height:260px}[data-map-preview] .studio-map-balloon{top:calc(51% - 30px)}}
</style>
<script type="module">
{!! file_get_contents(resource_path('js/components/venue-map-balloon.js')) !!}
const dialog=document.querySelector('[data-venue-map-dialog][data-map-preview]');
const canvas=dialog.querySelector('[data-venue-map-frame]');
const balloon=dialog.querySelector('[data-studio-map-balloon]');
const label=balloon.querySelector('[data-studio-map-balloon-title]');
const actions=balloon.querySelector('[data-studio-map-balloon-action]');
const toggle=dialog.querySelector('[data-venue-nearby-toggle]');
const options=dialog.querySelector('[data-venue-nearby-options]');
const nearby=dialog.querySelector('[data-map-nearby-pin]');
const radius=dialog.querySelector('[data-venue-nearby-radius]');
const distance=dialog.querySelector('[data-venue-nearby-distance]');
const land=dialog.querySelector('.studio-map-land');
let zoom=1;
function showBalloon(isNearby){
    label.textContent=isNearby?'Много спорта — Алтуфьево':canvas.dataset.name;
    actions.innerHTML=venueMapBalloonAction(isNearby?'https://mskba.ru/venues/22-mnogo-sporta-altufevo':canvas.dataset.venueUrl,isNearby||dialog.dataset.currentVenueLink==='true','https://mskba.ru');
    balloon.classList.toggle('is-nearby',isNearby);balloon.hidden=false;
}
dialog.querySelector('[data-map-current-pin]').addEventListener('click',()=>showBalloon(false));
nearby.addEventListener('click',()=>showBalloon(true));
balloon.querySelector('[data-studio-map-balloon-close]').addEventListener('click',()=>{balloon.hidden=true;});
document.querySelectorAll('[data-venue-map-open]').forEach(trigger=>trigger.addEventListener('click',()=>{if(!dialog.open)dialog.showModal();}));
dialog.querySelector('[data-venue-overlay-close]').addEventListener('click',()=>dialog.close());
let outside=null;
dialog.addEventListener('pointerdown',e=>{outside=e.target===dialog?e.pointerId:null;});
dialog.addEventListener('pointerup',e=>{if(outside===e.pointerId&&e.target===dialog)dialog.close();outside=null;});
dialog.addEventListener('pointercancel',()=>outside=null);
dialog.addEventListener('close',()=>{balloon.hidden=true;toggle.checked=false;options.hidden=true;nearby.hidden=true;radius.value='3';distance.textContent='3';});
toggle.addEventListener('change',()=>{options.hidden=!toggle.checked;if(!toggle.checked){nearby.hidden=true;if(balloon.classList.contains('is-nearby'))balloon.hidden=true;}});
radius.addEventListener('input',()=>distance.textContent=radius.value);
dialog.querySelector('[data-venue-nearby-apply]').addEventListener('click',()=>{nearby.hidden=!toggle.checked;});
dialog.querySelectorAll('[data-map-zoom]').forEach(button=>button.addEventListener('click',()=>{zoom=Math.min(1.5,Math.max(1,zoom+Number(button.dataset.mapZoom)*.15));land.style.transform='scale('+zoom+')';}));
@if($mapAutoOpen ?? false) dialog.showModal(); @endif
</script>
