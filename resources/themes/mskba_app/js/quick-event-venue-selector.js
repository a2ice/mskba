// Task 337: use the existing confirmed venue search API and canonical Yandex
// MSKBA placemarks for both themes. A typed string is NOT a selected venue ID.
import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';
import { nearbyVenueBounds, NEARBY_ZOOM_MARGIN_PX } from './quick-event-venue-map-geo.js';

(() => {
    const dialog=document.querySelector('[data-quick-event-dialog]');
    const selector=dialog?.querySelector('[data-quick-venue-selector]');
    const mapDialog=document.querySelector('[data-quick-venue-map-dialog]');
    if(!dialog||!selector||!mapDialog)return;

    const input=selector.querySelector('[data-quick-venue-search]');
    const hidden=selector.querySelector('[data-quick-venue]');
    const list=selector.querySelector('[data-quick-venue-results]');
    const clear=selector.querySelector('[data-quick-venue-clear]');
    const message=dialog.querySelector('[data-quick-venue-message]');
    const mapOpen=dialog.querySelector('[data-quick-venue-map-open]');
    const canvas=mapDialog.querySelector('[data-quick-venue-map-canvas]');
    const mapStatus=mapDialog.querySelector('[data-quick-venue-map-status]');
    const fallback=mapDialog.querySelector('[data-quick-venue-map-fallback]');
    const known=(()=>{try{return JSON.parse(dialog.dataset.venueMeta||'{}');}catch{return {};}})();
    let searchTimer=null,request=null,map=null,mapLoading=false,searchResults=[];

    const text=value=>String(value||'').trim();
    const metroNames=venue=>Array.isArray(venue?.metro_stations)
        ?venue.metro_stations.map(item=>text(typeof item==='string'?item:item?.name)).filter(Boolean):[];
    const normalize=venue=>{
        const existing=known[String(venue.id)]||{};
        return {...existing,...venue,metro_stations:metroNames(venue).length?metroNames(venue):metroNames(existing)};
    };
    const hideResults=()=>{
        list.hidden=true;list.replaceChildren();input.setAttribute('aria-expanded','false');
    };
    const showMessage=value=>{
        message.textContent=value||'';message.hidden=!value;
    };
    const invalidate=()=>{
        input.setCustomValidity('Выберите площадку из списка.');
    };
    function selected(venue){
        const item=normalize(venue);
        known[String(item.id)]=item;
        hidden.value=String(item.id);
        hydratedId=String(item.id);
        input.value=text(item.name);
        input.setCustomValidity('');
        input.removeAttribute('aria-invalid');
        clear.hidden=false;
        hideResults();showMessage('');
        hidden.dispatchEvent(new CustomEvent('change',{bubbles:true,detail:{venue:item}}));
    }
    function cleared(){
        hidden.value='';input.value='';clear.hidden=true;
        invalidate();hideResults();showMessage('');
        hidden.dispatchEvent(new Event('change',{bubbles:true}));
    }
    async function fetchVenues(query,limit,signal,venueId=null){
        const url=new URL(selector.dataset.searchUrl,location.href);
        url.searchParams.set('query',query);
        url.searchParams.set('confirmed_only','1');
        url.searchParams.set('limit',String(limit));
        if(venueId)url.searchParams.set('venue_id',String(venueId));
        const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin',signal});
        const payload=await response.json().catch(()=>({}));
        if(!response.ok)throw new Error(payload.message||'Не удалось получить площадки.');
        return Array.isArray(payload.venues)?payload.venues.map(normalize):[];
    }
    function renderResults(venues){
        searchResults=venues;list.replaceChildren();
        venues.forEach((venue,index)=>{
            const option=document.createElement('button');
            option.type='button';option.className='predictive-search__item';option.setAttribute('role','option');
            option.dataset.quickVenueOption=String(index);
            const name=document.createElement('strong');name.className='predictive-search__label';name.textContent=text(venue.name);
            option.append(name);
            const description=[text(venue.address),metroNames(venue).join(', ')].filter(Boolean).join(' · ');
            if(description){const small=document.createElement('small');small.className='predictive-search__meta';small.textContent=description;option.append(small);}
            list.append(option);
        });
        list.hidden=!venues.length;
        input.setAttribute('aria-expanded',String(venues.length>0));
        showMessage(venues.length?'':'Площадки не найдены.');
    }
    async function search(query){
        request?.abort();
        const controller=new AbortController();request=controller;
        try{
            const venues=await fetchVenues(query,30,controller.signal);
            if(!controller.signal.aborted&&input.value.trim()===query)renderResults(venues);
        }catch(error){
            if(error.name!=='AbortError')showMessage(error.message||'Не удалось загрузить площадки.');
        }finally{if(request===controller)request=null;}
    }
    input.addEventListener('input',()=>{
        clearTimeout(searchTimer);request?.abort();
        hidden.value='';clear.hidden=!input.value;
        invalidate();hideResults();showMessage('');
        hidden.dispatchEvent(new Event('change',{bubbles:true}));
        const query=input.value.trim();
        if(query)searchTimer=setTimeout(()=>void search(query),300);
    });
    input.addEventListener('keydown',event=>{
        if(event.key==='Escape'){hideResults();return;}
        if(event.key==='ArrowDown'){
            const first=list.querySelector('[data-quick-venue-option]');
            if(first){event.preventDefault();first.focus();}
        }
    });
    list.addEventListener('click',event=>{
        const button=event.target.closest?.('[data-quick-venue-option]');
        if(!button)return;
        const item=searchResults[Number(button.dataset.quickVenueOption)];
        if(item)selected(item);
    });
    list.addEventListener('keydown',event=>{
        if(event.key==='Escape'){hideResults();input.focus();}
        if(event.key==='ArrowDown'||event.key==='ArrowUp'){
            const options=[...list.querySelectorAll('[data-quick-venue-option]')];
            const current=options.indexOf(event.target.closest('[data-quick-venue-option]'));
            const target=options[current+(event.key==='ArrowDown'?1:-1)];
            if(target){event.preventDefault();target.focus();}
        }
    });
    document.addEventListener('pointerdown',event=>{
        if(!selector.contains(event.target))hideResults();
    });
    clear.addEventListener('click',()=>{cleared();input.focus();});

    // External callers (e.g. "Create event" on the venue page) update the
    // canonical hidden ID. Hydrate its display and location from known data/API.
    let hydratedId=null;
    hidden.addEventListener('change',event=>{
        const id=hidden.value;
        if(!id){hydratedId=null;return;}
        if(id===hydratedId&&input.value.trim())return;
        const venue=event.detail?.venue||known[id];
        if(venue){hydratedId=id;selected(venue);return;}
        void fetchVenues('',1,null,id).then(items=>{
            if(hidden.value===id&&items[0]){hydratedId=id;selected(items[0]);}
        }).catch(()=>{if(hidden.value===id)showMessage('Не удалось получить выбранную площадку.');});
    });
    if(hidden.value){hydratedId=hidden.value;if(known[hidden.value])selected(known[hidden.value]);}
    else invalidate();

    // The map is a two-step chooser: marker -> information card -> "Выбрать".
    // The card mirrors the venue map card on the MSKBA homepage.
    const preview=mapDialog.querySelector('[data-quick-venue-map-preview]');
    const previewName=mapDialog.querySelector('[data-quick-venue-map-preview-name]');
    const previewAddress=mapDialog.querySelector('[data-quick-venue-map-preview-address]');
    const previewMetro=mapDialog.querySelector('[data-quick-venue-map-preview-metro]');
    const previewTags=mapDialog.querySelector('[data-quick-venue-map-preview-tags]');
    const previewSelect=mapDialog.querySelector('[data-quick-venue-map-select]');
    const previewClose=mapDialog.querySelector('[data-quick-venue-map-preview-close]');
    const nearbyButton=mapDialog.querySelector('[data-quick-venue-map-nearby]');
    let cardVenue=null;
    let plottedVenues=[];
    let originalBounds=null;
    let userCoords=null;
    let userMarker=null;
    let showingNearby=false;
    let mapEpoch=0;

    const validPoint=venue=>{
        const lat=Number(venue?.latitude),lon=Number(venue?.longitude);
        return venue?.latitude!=null&&venue?.longitude!=null
            &&Number.isFinite(lat)&&Number.isFinite(lon)
            &&Math.abs(lat)<=90&&Math.abs(lon)<=180&&!(lat===0&&lon===0);
    };
    const dismissPreview=()=>{cardVenue=null;preview.hidden=true;};
    function showPreview(venue){
        const item=normalize(venue);
        cardVenue=item;
        previewName.textContent=text(item.name)||'Баскетбольная площадка';
        previewAddress.textContent=text(item.address)||'Адрес не указан';
        const metro=metroNames(item).join(', ');
        previewMetro.textContent=metro?'Метро: '+metro:'';
        previewMetro.hidden=!metro;
        previewTags.replaceChildren();
        const labels=[
            item.has_free_court ? (item.has_paid_court?'Есть бесплатные залы':'Бесплатно') : null,
            item.has_paid_court ? (item.has_free_court?'Есть платные залы':'Платно') : null,
            item.has_approval_court ? 'Есть залы с подтверждением' : null,
        ].filter(Boolean);
        labels.forEach(label=>{
            const tag=document.createElement('span');
            tag.textContent=label;
            previewTags.appendChild(tag);
        });
        preview.hidden=false;
    }
    previewClose.addEventListener('click',dismissPreview);
    previewSelect.addEventListener('click',()=>{
        if(!cardVenue)return;
        selected(cardVenue);
        mapDialog.close();
    });

    const addFallback=venues=>{
        fallback.replaceChildren();
        venues.forEach(venue=>{
            const btn=document.createElement('button');btn.type='button';
            btn.textContent=[venue.name,venue.address].filter(Boolean).join(' — ');
            btn.addEventListener('click',()=>showPreview(venue));
            fallback.append(btn);
        });
        fallback.hidden=!venues.length;
    };
    function fitAllVenues(){
        if(!map)return;
        const activeMap=map;
        showingNearby=false;
        nearbyButton.textContent='Рядом со мной';
        nearbyButton.setAttribute('aria-pressed','false');
        if(!originalBounds)return;
        void Promise.resolve(activeMap.setBounds(originalBounds,{
            checkZoomRange:true,preciseZoom:true,zoomMargin:48,
        })).then(()=>{
            if(map===activeMap&&activeMap.getZoom()>15)activeMap.setZoom(15);
        }).catch(()=>{});
    }
    function displayNearby(){
        if(!map||!userCoords)return;
        if(showingNearby){fitAllVenues();return;}
        const result=nearbyVenueBounds(userCoords,plottedVenues);
        if(!result){
            mapStatus.textContent='Поблизости нет площадок с координатами.';
            return;
        }
        showingNearby=true;
        nearbyButton.textContent='Показать все';
        nearbyButton.setAttribute('aria-pressed','true');
        // Symmetric bounds keep the user at the viewport's geographic center;
        // 48px allows 28px branded marker footprints + 20px clear margin.
        const activeMap=map;
        void Promise.resolve(activeMap.setBounds(result.bounds,{
            checkZoomRange:true,preciseZoom:true,zoomMargin:NEARBY_ZOOM_MARGIN_PX,
        })).then(()=>{
            if(map===activeMap&&showingNearby&&activeMap.getZoom()>17)activeMap.setZoom(17);
        }).catch(()=>{});
    }
    nearbyButton.addEventListener('click',displayNearby);

    function locateBrowserUser(epoch){
        // Only ask after the user explicitly opened the map. Never send
        // coordinates to /venues/search and never persist them in storage.
        if(!navigator.geolocation||window.isSecureContext===false)return;
        navigator.geolocation.getCurrentPosition(position=>{
            if(epoch!==mapEpoch||!mapDialog.open||!map)return;
            const latitude=Number(position.coords?.latitude);
            const longitude=Number(position.coords?.longitude);
            if(!validPoint({latitude,longitude}))return;
            userCoords={latitude,longitude};
            if(userMarker)map.geoObjects.remove(userMarker);
            userMarker=new window.ymaps.Placemark([latitude,longitude],{
                hintContent:'Вы здесь',
            },{
                preset:'islands#blueCircleDotIcon',
                iconColor:'#2c8df6',
            });
            map.geoObjects.add(userMarker);
            nearbyButton.hidden=false;
        },()=>{
            // Permission denial/timeout must not interfere with map selection.
            // Leave the optional nearby control hidden when coordinates fail.
            if(epoch===mapEpoch)nearbyButton.hidden=true;
        },{
            enableHighAccuracy:false,timeout:10000,maximumAge:60000,
        });
    }

    async function openMap(){
        if(!mapDialog.open)mapDialog.showModal();
        if(mapLoading)return;
        const epoch=++mapEpoch;
        mapLoading=true;
        dismissPreview();
        nearbyButton.hidden=true;
        nearbyButton.textContent='Рядом со мной';
        nearbyButton.setAttribute('aria-pressed','false');
        showingNearby=false;
        userCoords=null;
        userMarker=null;
        mapStatus.textContent='Загружаем площадки…';
        try{
            const venues=await fetchVenues('',200);
            if(epoch!==mapEpoch||!mapDialog.open)return;
            venues.forEach(venue=>known[String(venue.id)]=venue);
            plottedVenues=venues.filter(validPoint);
            addFallback(venues.filter(v=>!validPoint(v)));
            if(!plottedVenues.length){
                mapStatus.textContent='Нет площадок с координатами. Выберите площадку из списка.';
                return;
            }
            if(!canvas.dataset.mapApiKey){
                mapStatus.textContent='Яндекс Карты временно недоступны. Выберите площадку из списка.';
                addFallback(venues);return;
            }
            await loadYandexMaps(canvas.dataset.mapApiKey);
            await new Promise(resolve=>window.ymaps.ready(resolve));
            if(epoch!==mapEpoch||!mapDialog.open)return;
            map?.destroy();
            map=new window.ymaps.Map(canvas,{
                center:[55.751244,37.618423],zoom:10,
                controls:['zoomControl','fullscreenControl'],
            });
            const markers=plottedVenues.map(venue=>{
                const marker=venuePlacemark([
                    Number(venue.latitude),Number(venue.longitude),
                ],{
                    hintContent:text(venue.name),
                });
                marker.events.add('click',()=>showPreview(venue));
                return marker;
            });
            const clusterer=new window.ymaps.Clusterer({
                groupByCoordinates:false,clusterDisableClickZoom:false,
                clusterOpenBalloonOnClick:false,
                geoObjectHideIconOnBalloonOpen:false,
                clusterHideIconOnBalloonOpen:false,
                preset:'islands#invertedOrangeClusterIcons',
            });
            clusterer.add(markers);
            map.geoObjects.add(clusterer);
            map.container.fitToViewport();
            originalBounds=clusterer.getBounds();
            fitAllVenues();
            mapStatus.textContent='Нажмите на площадку на карте.';
            locateBrowserUser(epoch);
        }catch(error){
            if(epoch===mapEpoch&&mapDialog.open){
                mapStatus.textContent='Не удалось открыть карту. Выберите площадку из списка.';
                addFallback(Object.values(known));
            }
        }finally{
            if(epoch===mapEpoch)mapLoading=false;
        }
    }
    mapOpen.addEventListener('click',()=>void openMap());
    mapDialog.querySelectorAll('[data-quick-venue-map-close]').forEach(button=>
        button.addEventListener('click',()=>mapDialog.close()));
    mapDialog.addEventListener('close',()=>{
        ++mapEpoch;
        mapLoading=false;
        map?.destroy();map=null;
        originalBounds=null;
        plottedVenues=[];
        userCoords=null;userMarker=null;
        showingNearby=false;
        nearbyButton.hidden=true;
        nearbyButton.textContent='Рядом со мной';
        nearbyButton.setAttribute('aria-pressed','false');
        dismissPreview();
    });
    let outsidePointer=null;
    mapDialog.addEventListener('pointerdown',event=>{
        outsidePointer=event.target===mapDialog?event.pointerId:null;
    });
    mapDialog.addEventListener('pointerup',event=>{
        if(outsidePointer===event.pointerId&&event.target===mapDialog)mapDialog.close();
        outsidePointer=null;
    });
    mapDialog.addEventListener('pointercancel',()=>{outsidePointer=null;});
})();
