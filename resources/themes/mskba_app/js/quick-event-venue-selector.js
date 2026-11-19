// Task 337: use the existing confirmed venue search API and canonical Yandex
// MSKBA placemarks for both themes. A typed string is NOT a selected venue ID.
import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';

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
            option.type='button';option.setAttribute('role','option');
            option.dataset.quickVenueOption=String(index);
            const name=document.createElement('strong');name.textContent=text(venue.name);
            option.append(name);
            const description=[text(venue.address),metroNames(venue).join(', ')].filter(Boolean).join(' · ');
            if(description){const small=document.createElement('small');small.textContent=description;option.append(small);}
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

    const validPoint=venue=>Number.isFinite(Number(venue.latitude))
        &&Number.isFinite(Number(venue.longitude))&&Number(venue.latitude)!==0&&Number(venue.longitude)!==0;
    const addFallback=venues=>{
        fallback.replaceChildren();
        venues.forEach(venue=>{
            const btn=document.createElement('button');btn.type='button';
            btn.textContent=[venue.name,venue.address].filter(Boolean).join(' — ');
            btn.addEventListener('click',()=>{selected(venue);mapDialog.close();});
            fallback.append(btn);
        });
        fallback.hidden=!venues.length;
    };
    async function openMap(){
        if(!mapDialog.open)mapDialog.showModal();
        mapStatus.textContent='Загружаем площадки…';
        if(mapLoading)return;
        mapLoading=true;
        try{
            const venues=await fetchVenues('',200);
            if(!mapDialog.open)return;
            venues.forEach(venue=>known[String(venue.id)]=venue);
            const plotted=venues.filter(validPoint);
            addFallback(venues.filter(v=>!validPoint(v)));
            if(!plotted.length){mapStatus.textContent='Нет площадок с координатами. Выбери площадку из списка.';return;}
            if(!canvas.dataset.mapApiKey){mapStatus.textContent='Яндекс Карты временно недоступны. Выбери площадку из списка.';addFallback(venues);return;}
            await loadYandexMaps(canvas.dataset.mapApiKey);
            await new Promise(resolve=>window.ymaps.ready(resolve));
            if(!mapDialog.open)return;
            map?.destroy();
            map=new window.ymaps.Map(canvas,{
                center:[55.751244,37.618423],zoom:10,controls:['zoomControl','fullscreenControl'],
            });
            const points=plotted.map(venue=>{
                const marker=venuePlacemark([Number(venue.latitude),Number(venue.longitude)],{
                    hintContent:text(venue.name),
                });
                marker.events.add('click',()=>{
                    selected(venue);mapDialog.close();
                });
                return marker;
            });
            const clusterer=new window.ymaps.Clusterer({groupByCoordinates:false,clusterDisableClickZoom:false});
            clusterer.add(points);map.geoObjects.add(clusterer);
            map.container.fitToViewport();
            const bounds=clusterer.getBounds();
            if(bounds)Promise.resolve(map.setBounds(bounds,{checkZoomRange:true,zoomMargin:40})).then(()=>{
                if(map&&map.getZoom()>15)map.setZoom(15);
            }).catch(()=>{});
            mapStatus.textContent='Нажми на фирменную точку, чтобы выбрать площадку.';
        }catch(error){
            if(mapDialog.open){mapStatus.textContent='Не удалось открыть карту. Выбери площадку из списка.';addFallback(Object.values(known));}
        }finally{mapLoading=false;}
    }
    mapOpen.addEventListener('click',()=>void openMap());
    mapDialog.querySelectorAll('[data-quick-venue-map-close]').forEach(button=>
        button.addEventListener('click',()=>mapDialog.close()));
    mapDialog.addEventListener('close',()=>{map?.destroy();map=null;});
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
