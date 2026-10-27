<script setup>
import { computed, onMounted, onBeforeUnmount, nextTick, reactive, ref, watch } from 'vue';
const props = defineProps({ options: { type:Object, required:true } });
const STORAGE = 'mskba:play-intent:pending:v1';
const visible=ref(false), el=ref(null), heading=ref(null), expanded=ref(false);
const busy=ref(false), success=ref(null), error=ref(''), errors=ref({});
const interestName=ref(''), manuallyNamed=ref(false), step=ref('form');
const hasExpiry=ref(false), expiryDate=ref('');
const venueSearch=ref(''), venueSuggestions=ref([]), searchingVenue=ref(false), venueMenu=ref(false);
const matchingVenues=ref([]), matchingLoading=ref(false), matchingError=ref(''), matchingOpen=ref(false);
const excludedSearch=ref(''), excludedSuggestions=ref([]), excludedMenu=ref(false), excludedVenues=ref([]), venueNotice=ref('');
let venueTimer=null, excludedTimer=null;
const cadence=ref('flexible'), dayIds=ref([]), dates=ref([]), period=ref('14'), dateMode=ref('range'), dateInput=ref('');
const formats=ref([]), duration=ref(''), similar=ref(false), radius=ref(10), access=ref('any'), venueKind=ref('any');
const editingId=ref(null), selectedVenueId=ref(props.options.venueId ?? null), venueDisabled=ref(false);
const contextSource=ref('play'), contextType=ref('venue'), contextId=ref(props.options.venueId??null);
const favoriteOpen=ref(false), favoriteSelected=ref(true), favoriteState=ref('unknown'), favoriteBusy=ref(false);
const favoriteError=ref(''), alreadySubscribed=ref(false), playOpen=ref(true);
const hasFavorite=computed(()=>Number(contextId.value)>0 && !!contextType.value);
// Each entity can expose its own optional module. Unconfigured types show
// only the generic favorite confirmation, not an irrelevant game editor.
const favoriteContexts={
    venue:{title:'Площадка добавлена в избранное',
        description:'Ты сможешь найти площадку в разделе «Избранное» личного кабинета.',
        module:'play'},
    team:{title:'Команда добавлена в избранное',
        description:'Ты сможешь найти команду в разделе «Избранное» личного кабинета.'},
    event:{title:'Мероприятие добавлено в избранное',
        description:'Ты сможешь найти мероприятие в разделе «Избранное» личного кабинета.'},
};
const favoriteContext=computed(()=>favoriteContexts[contextType.value]||{
    title:'Добавлено в избранное',
    description:'Запись сохранена в разделе «Избранное» личного кабинета.',
});
const favoriteTitle=computed(()=>favoriteContext.value.title);
const favoriteDescription=computed(()=>favoriteContext.value.description);
const contextualModule=computed(()=>favoriteContext.value.module||null);
const favoriteExtraVisible=computed(()=>contextSource.value==='play' && hasFavorite.value
    && contextType.value==='venue' && !!chosenVenueId.value && !editingId.value);
const favoriteRetryNeeded=ref(false);
let pendingSuccessToast = null;
const supportsPlay=computed(()=>!hasFavorite.value||contextualModule.value==='play');
const titleText=computed(()=>contextSource.value==='favorite'?favoriteTitle.value:
    editingId.value?'Редактировать подписку':'Хочу здесь играть');
const selectedVenueName=ref(props.options.venueName ?? ''), selectedVenueUrl=ref(props.options.venueUrl ?? '');
const chosenVenueId=computed(() => venueDisabled.value ? null : selectedVenueId.value);
const latitude=ref(props.options.venueLatitude ?? ''), longitude=ref(props.options.venueLongitude ?? '');
const notifyMatches=ref(true), notifyOffers=ref(true), notifyVenueNews=ref(true), showOnVenue=ref(true), amenities=ref([]), priority=reactive({});
const gameChannels=ref(['in_app']), venueChannels=ref(['in_app']), offersChannels=ref(['in_app']);
const channelsLoading=ref(false), channelError=ref('');
const channelAvailability=ref({
    in_app:{available:true,reason:null,action:null},
    telegram:{available:false,reason:null,action:'add_contact'},
    email:{available:false,reason:null,action:'add_contact'},
});
const channelList=[{key:'in_app',label:'Личные сообщения'},
    {key:'telegram',label:'Telegram'},{key:'email',label:'Email'}];
const topicChannels={games:gameChannels,offers:offersChannels,venue:venueChannels};
function channelChecked(topic,key){return topicChannels[topic]?.value.includes(key)===true;}
function toggleChannel(topic,key,enabled){
    const state=topicChannels[topic];
    if(!state)return;
    state.value=enabled?[...new Set([...state.value,key])]:state.value.filter(ch=>ch!==key);
}
function channelEnabled(key){return channelAvailability.value[key]?.available===true;}
function channelAction(key){return channelAvailability.value[key]?.action||null;}
function addContact(key){
    if(!['telegram','email'].includes(key))return;
    window.dispatchEvent(new CustomEvent('mskba:contact:add',{detail:{type:key}}));
}
async function refreshChannels(){
    if(!isAuth.value||!props.options.endpoints?.channels)return;
    channelsLoading.value=true;channelError.value='';
    try{
        const response=await fetch(props.options.endpoints.channels,{
            credentials:'same-origin',headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error('Не удалось проверить доступность каналов.');
        channelAvailability.value=(await response.json()).channels;
        // A temporarily unavailable contact must not erase the user's
        // previous choices. Enabled topics are validated again on save.
    }catch(e){channelError.value=e.message;}
    finally{channelsLoading.value=false;}
}

async function syncFavorite(){
    if(!hasFavorite.value)return;
    favoriteBusy.value=true;favoriteError.value='';
    try{
        const venue=contextType.value==='venue';
        const url=new URL(venue?(props.options.endpoints?.favoriteStatuses||'/favorites/venue-statuses'):'/favorites/status',location.origin);
        if(venue)url.searchParams.append('ids[]',String(contextId.value));
        else{url.searchParams.set('type',contextType.value);url.searchParams.set('id',String(contextId.value));}
        const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('Не удалось проверить избранное.');
        const data=await response.json();
        const item=venue?data.data?.[String(contextId.value)]:data;
        favoriteState.value=item?.state||'none';
        alreadySubscribed.value=Boolean(item?.subscribed);
        if(contextSource.value==='edit')favoriteSelected.value=favoriteState.value==='active';
        if(contextSource.value==='favorite'&&favoriteState.value!=='active')
            favoriteError.value='Не удалось подтвердить добавление в избранное.';
    }catch(e){favoriteState.value='unknown';favoriteError.value=e.message;}
    finally{favoriteBusy.value=false;}
}
function toast(message,failed=false){
    window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message,failed}}));
}
async function applyFavorite(){
    if(!hasFavorite.value)return true;
    if(favoriteState.value==='unknown'){favoriteError.value='Не удалось проверить текущее состояние избранного.';toast(favoriteError.value,true);return false;}
    if((favoriteState.value==='active')===favoriteSelected.value)return true;
    favoriteBusy.value=true;favoriteError.value='';
    try{
        const response=await fetch(props.options.endpoints?.favoritesToggle||'/favorites/toggle',{
            method:'POST',credentials:'same-origin',
            headers:{Accept:'application/json','Content-Type':'application/json',
                'X-Requested-With':'XMLHttpRequest',
                'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
            body:JSON.stringify({type:contextType.value,id:Number(contextId.value)})});
        if(!response.ok)throw Error('Не удалось изменить избранное.');
        const data=await response.json();
        favoriteState.value=data.state;
        if(contextType.value==='venue')window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',{
            detail:{venueId:Number(contextId.value),state:data.state,count:data.count},
        }));
        // Success for the optional favorite is represented by the checked
        // control; creating the subscription produces the only success toast.
        return true;
    }catch(e){favoriteError.value=e.message;toast(e.message,true);return false;}
    finally{favoriteBusy.value=false;}
}
async function saveFavoriteOnly(){
    // The heart has ALREADY persisted the favorite. The modal confirms the
    // result or offers extra actions; closing it never mutates favorites.
    close();
}
async function retryFavorite(){
    if(await applyFavorite())favoriteRetryNeeded.value=false;
}
async function contextOpen(event){
    const detail=event.detail||{},id=Number(detail.id);
    if(!Number.isInteger(id)||id<=0)return;
    pendingSuccessToast=null;
    contextSource.value='favorite';contextType.value=detail.type||'venue';contextId.value=id;
    playOpen.value=false;favoriteOpen.value=true;favoriteSelected.value=true;favoriteState.value='active';
    favoriteRetryNeeded.value=false;
    alreadySubscribed.value=false;favoriteError.value='';
    editingId.value=null;step.value='form';success.value=null;error.value='';errors.value={};
    selectedVenueId.value=contextType.value==='venue'?id:null;
    selectedVenueName.value=detail.name||'';selectedVenueUrl.value='';venueSearch.value=selectedVenueName.value;
    // For non-venue favorites the entity title is only used for context text.
    // No game-search state or form is created.
    manuallyNamed.value=false;interestName.value='';requestId=null;
    venueDisabled.value=false;similar.value=false;radius.value=10;cadence.value='flexible';
    hasExpiry.value=false;expiryDate.value='';dayIds.value=[];dates.value=[];formats.value=[];
    excludedVenues.value=[];latitude.value='';longitude.value='';access.value='any';venueKind.value='any';
    for(const key of Object.keys(priority))delete priority[key];
    for(const key of Object.keys(windows))delete windows[key];
    windows.all=[{part:'any',from:'',to:''}];
    duration.value='';notifyMatches.value=true;notifyOffers.value=false;notifyVenueNews.value=true;
    showOnVenue.value=false;gameChannels.value=['in_app'];venueChannels.value=['in_app'];offersChannels.value=['in_app'];
    await openDialog();
    if(contextType.value==='venue'){
        try{
            const v=(await fetchVenues('',id))[0];
            if(v){selectedVenueName.value=v.name;selectedVenueUrl.value=v.url||'';
                venueSearch.value=v.name;latitude.value=v.latitude??'';longitude.value=v.longitude??'';}
        }catch{}
    }
    void syncFavorite();
}
const windows=reactive({ all:[{part:'any',from:'',to:''}] });
const days=[{id:1,name:'Пн'},{id:2,name:'Вт'},{id:3,name:'Ср'},{id:4,name:'Чт'},{id:5,name:'Пт'},{id:6,name:'Сб'},{id:7,name:'Вс'}];
const parts=[{id:'any',label:'Не важно'},{id:'morning',label:'Утро'},{id:'day',label:'День'},{id:'evening',label:'Вечер'},{id:'night',label:'Ночь'}];
const limits={morning:['06:00','12:00'],day:['12:00','18:00'],evening:['18:00','22:00'],night:['22:00','06:00']};
const isAuth=ref(Boolean(props.options.authenticated));let onceResume=false, requestId=null, restoreFocus=null, overflow='';
const keys=computed(()=>cadence.value==='once'&&dateMode.value==='dates'?dates.value:(dayIds.value.length?dayIds.value:['all']));
const groups=computed(()=>['all','indoor','outdoor'].map(k=>({key:k,label:{all:'Общие',indoor:'В залах',outdoor:'На улице'}[k],items:amenities.value.filter(a=>a.applies_to===k)})).filter(x=>x.items.length));
const nameForKey=(k)=>k==='all'?'Любой день':typeof k==='number'?days.find(d=>d.id===k)?.name:k;
const minute=(x)=>Number(x.slice(0,2))*60+Number(x.slice(3,5));
const formatTime=(n)=>String(Math.floor(n/60)%24).padStart(2,'0')+':'+String(n%60).padStart(2,'0');
function clockOptions(part,from=null){
    if(!limits[part])return[];
    let start=minute(limits[part][0]), end=minute(limits[part][1]);
    if(part==='night')end+=1440;
    if(from===null)end-=30;else start=minute(from)+30+(part==='night'&&minute(from)<360?1440:0);
    const out=[];for(let n=start;n<=end;n+=30)out.push({v:formatTime(n),t:formatTime(n)+(n>=1440?' +1 день':'')});
    return out;
}
function dayWindows(key){if(!windows[key])windows[key]=[{part:'any',from:'',to:''}];return windows[key];}
function changePart(w,part){w.part=part;w.from=part==='any'?'':limits[part][0];w.to=part==='any'?'':limits[part][1];}
function changeFrom(w){const os=clockOptions(w.part,w.from);if(!os.some(x=>x.v===w.to))w.to=os.at(-1)?.v||'';}
function addInterval(key){const list=dayWindows(key);if(list.length<4&&!list.some(x=>x.part==='any'))list.push({part:'evening',from:'18:00',to:'22:00'});}
function toggleDay(n){dayIds.value=dayIds.value.includes(n)?dayIds.value.filter(x=>x!==n):[...dayIds.value,n].sort();}
function toggleFormat(x){formats.value=formats.value.includes(x)?formats.value.filter(f=>f!==x):[...formats.value,x];}
function today(){const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10);}
function dayAhead(offset){const d=new Date(today()+'T12:00:00');d.setDate(d.getDate()+offset);return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');}
function addDate(){if(dateInput.value>=today()&&!dates.value.includes(dateInput.value))dates.value=[...dates.value,dateInput.value].sort();}
function serialize(){
    const out=[];
    for(const key of keys.value)for(const w of dayWindows(key)){
        if(w.part==='any')continue;
        out.push(Object.assign(
            {},typeof key==='number'?{weekday:key}:key!=='all'?{date:key}:{},
            {start_time:w.from,end_time:w.to,end_next_day:w.part==='night'&&minute(w.to)<=minute(w.from)}
        ));
    }
    const payload={
        client_request_id:requestId||(requestId=crypto.randomUUID()),name:interestName.value.trim() || null,venue_id:chosenVenueId.value,
        expires_at:hasExpiry.value ? expiryDate.value : null,
        excluded_venue_ids:chosenVenueId.value && similar.value ? excludedVenues.value.map(v=>v.id) : [],
        include_similar_venues:Boolean(chosenVenueId.value && similar.value),cadence:cadence.value,
        weekdays:cadence.value==='once'&&dateMode.value==='dates'?[]:dayIds.value,
        dates:cadence.value==='once'&&dateMode.value==='dates'?dates.value:[],
        windows:out,formats:formats.value,duration_minutes:duration.value?Number(duration.value):null,
        access_mode:access.value,venue_kind:(!chosenVenueId.value || similar.value)?venueKind.value:'any',amenity_preferences:Object.entries(priority).filter(x=>x[1]).map(x=>({amenity_id:Number(x[0]),priority:x[1]})),
        notify_matches:notifyMatches.value,notify_offers:notifyOffers.value,
        notify_venue_news:Boolean(chosenVenueId.value && notifyVenueNews.value),
        game_delivery_channels:gameChannels.value,venue_delivery_channels:venueChannels.value,
        offers_delivery_channels:offersChannels.value,
        show_on_venue:Boolean(showOnVenue.value),
    };
    if(cadence.value==='once'&&dateMode.value==='range'){
        payload.period_from=today();payload.period_until=dayAhead(Number(period.value)-1);
    }
    if(chosenVenueId.value && similar.value){payload.center_latitude=Number(latitude.value);payload.center_longitude=Number(longitude.value);payload.radius_km=Number(radius.value);}
    return payload;
}
function restore(data){
    interestName.value=data.name || '';manuallyNamed.value=data.manuallyNamed ?? Boolean(data.id);
    hasExpiry.value=Boolean(data.expires_at);
    expiryDate.value=data.expires_at ? String(data.expires_at).slice(0,10) : '';
    selectedVenueId.value=data.venue_id ?? null; venueDisabled.value=false;
    showOnVenue.value=data.show_on_venue !== false;
    selectedVenueName.value=data.venue?.name ?? (selectedVenueId.value && selectedVenueId.value === props.options.venueId ? props.options.venueName : '') ?? '';
    selectedVenueUrl.value=data.venue?.url ?? (selectedVenueId.value && selectedVenueId.value === props.options.venueId ? props.options.venueUrl : '') ?? '';
    venueSearch.value=selectedVenueName.value;
    excludedVenues.value=Array.isArray(data.excludedVenues) ? data.excludedVenues : [];
    requestId=data.client_request_id ?? null;cadence.value=data.cadence;dayIds.value=data.weekdays||[];
    dates.value=data.dates||[];dateMode.value=dates.value.length?'dates':'range';
    if(data.period_from && data.period_until){
        const from=String(data.period_from).slice(0,10), to=String(data.period_until).slice(0,10);
        const d=Math.round((Date.parse(to+'T12:00:00Z')-Date.parse(from+'T12:00:00Z'))/86400000)+1;
        period.value=String([7,14,30].includes(d)?d:14);
    }
    formats.value=data.formats||[];duration.value=String(data.duration_minutes||'');
    similar.value=Boolean(data.include_similar_venues);radius.value=data.radius_km||10;
    latitude.value=data.center_latitude??latitude.value;longitude.value=data.center_longitude??longitude.value;
    access.value=data.access_mode||'any';venueKind.value=(!selectedVenueId.value || data.include_similar_venues) ? (data.venue_kind||'any') : 'any';notifyMatches.value=data.notify_matches!==false;notifyOffers.value=data.notify_offers===true;
    notifyVenueNews.value=data.notify_venue_news!==false;
    gameChannels.value=Array.isArray(data.game_delivery_channels)?data.game_delivery_channels:['in_app'];
    venueChannels.value=Array.isArray(data.venue_delivery_channels)?data.venue_delivery_channels:['in_app'];
    offersChannels.value=Array.isArray(data.offers_delivery_channels)?data.offers_delivery_channels:['in_app'];
    for(const item of data.amenity_preferences||[])priority[item.amenity_id]=item.priority;
    for(const key of Object.keys(windows))delete windows[key];
    for(const w of data.windows||[]){
        const key=w.date||w.weekday||'all';
        const part=w.end_next_day?'night':minute(w.start_time)<720?'morning':minute(w.start_time)<1080?'day':'evening';
        if(!windows[key])windows[key]=[];
        windows[key].push({part,from:w.start_time,to:w.end_time});
    }
}

const suggestedName=computed(()=>{
    if(!chosenVenueId.value) return 'Игры по моим условиям';
    const suffix=similar.value?' и похожие':'';
    return (selectedVenueName.value||'Площадка').slice(0,80-suffix.length)+suffix;
});
watch(suggestedName,name=>{if(!manuallyNamed.value) interestName.value=name;},{immediate:true});
watch([chosenVenueId,radius,similar],()=>{
    if(!similar.value||!chosenVenueId.value){excludedMenu.value=false;return;}
    const original=excludedVenues.value.length;
    excludedVenues.value=excludedVenues.value.filter(v=>withinRadius(v));
    if(original!==excludedVenues.value.length)venueNotice.value='Некоторые исключения удалены: они вне выбранного радиуса.';
});
function distanceMeters(venue){
    if(latitude.value===''||longitude.value===''||venue.latitude==null||venue.longitude==null)return Infinity;
    const radians=n=>n*Math.PI/180;
    const a=radians(Number(latitude.value)),b=radians(Number(venue.latitude));
    const lat=radians(Number(venue.latitude)-Number(latitude.value)),lon=radians(Number(venue.longitude)-Number(longitude.value));
    const x=Math.sin(lat/2)**2+Math.cos(a)*Math.cos(b)*Math.sin(lon/2)**2;
    return 6371000*2*Math.atan2(Math.sqrt(x),Math.sqrt(Math.max(0,1-x)));
}
function withinRadius(v){return Number(v.id)!==Number(chosenVenueId.value)&&distanceMeters(v)<=Number(radius.value)*1000;}
async function fetchVenues(search='',id=null){
    const url=new URL(props.options.endpoints.venuesSearch||'/venues/search',location.origin);
    url.searchParams.set('confirmed_only','1');url.searchParams.set('limit','30');
    if(id)url.searchParams.set('venue_id',String(id));
    else if(search)url.searchParams.set('query',search);
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!response.ok)throw Error('Площадки не удалось загрузить');
    return (await response.json()).venues||[];
}
function searchVenueList(which){
    const excluding=which==='excluded',term=excluding?excludedSearch.value:venueSearch.value;
    if(excluding && (!chosenVenueId.value||!similar.value))return;
    clearTimeout(excluding?excludedTimer:venueTimer);
    const run=async()=>{
        if(!excluding)searchingVenue.value=true;
        try{
            const matches=await fetchVenues(term);
            if(excluding)excludedSuggestions.value=matches.filter(v=>withinRadius(v)&&!excludedVenues.value.some(a=>a.id===v.id));
            else venueSuggestions.value=matches;
        }catch{if(excluding)excludedSuggestions.value=[];else venueSuggestions.value=[];}
        finally{searchingVenue.value=false;}
    };
    if(excluding){excludedMenu.value=true;excludedTimer=setTimeout(run,230);}
    else{venueMenu.value=true;venueTimer=setTimeout(run,230);}
}
function chooseVenue(v){
    selectedVenueId.value=v.id;selectedVenueName.value=v.name;selectedVenueUrl.value=v.url||'';
    if(contextSource.value==='play' && contextType.value==='venue') {
        contextId.value=v.id;favoriteState.value='unknown';
        void syncFavorite();
    }
    latitude.value=v.latitude??'';longitude.value=v.longitude??'';
    venueDisabled.value=false;venueSearch.value=v.name;venueMenu.value=false;
    excludedVenues.value=[];venueNotice.value='';

}
function chooseExcluded(v){if(withinRadius(v)&&!excludedVenues.value.some(x=>x.id===v.id))excludedVenues.value=[...excludedVenues.value,v];excludedSearch.value='';excludedMenu.value=false;}
async function hydrateVenue(){
    if(chosenVenueId.value && (latitude.value===''||longitude.value==='')){
        try{const v=(await fetchVenues('',chosenVenueId.value))[0];if(v){latitude.value=v.latitude??'';longitude.value=v.longitude??'';}}
        catch{}
    }
}
async function hydrateExcluded(ids){
    if(!ids?.length||excludedVenues.value.length)return;
    const found=await Promise.all(ids.map(id=>fetchVenues('',id).catch(()=>[])));
    excludedVenues.value=found.flat().filter(v=>ids.includes(v.id));
}
function validateExpiry() {
    if(!hasExpiry.value)return true;
    if(!/^\d{4}-\d{2}-\d{2}$/.test(expiryDate.value) || expiryDate.value<today()){
        error.value='Укажи дату окончания подписки не раньше сегодняшнего дня.';
        return false;
    }
    return true;
}
function next(){
    error.value='';errors.value={};
    if(!validateExpiry())return;
    if(cadence.value==='once'&&dateMode.value==='dates'&&!dates.value.length){error.value='Выбери хотя бы одну дату.';return;}
    if(chosenVenueId.value&&similar.value&&(latitude.value===''||longitude.value==='')){error.value='Не удалось определить центр поиска похожих площадок.';return;}
    if(!isAuth.value){beginLogin();return;}
    step.value='options';
    void refreshChannels();
}

function unlinkVenue(){
    // Preserve the anchor and allow restoring it before saving.
    venueDisabled.value=!venueDisabled.value;
    if(venueDisabled.value) similar.value=false;
    if(contextSource.value==='play') {
        contextId.value=venueDisabled.value?null:selectedVenueId.value;
        if(!venueDisabled.value)void syncFavorite();
    }
    venueNotice.value='';
}
function readPending(){
    try{
        const x=JSON.parse(sessionStorage.getItem(STORAGE)||'null');
        if(!x||Date.now()-x.createdAt>1800000){sessionStorage.removeItem(STORAGE);return null;}
        return x.path===location.pathname?x:null;
    }catch{return null;}
}
function close(forAuth=false){
    if(busy.value)return;
    el.value?.close();visible.value=false;document.body.style.overflow=overflow;
    if(!forAuth) {
        restoreFocus?.focus?.({preventScroll:true});
        // The native <dialog> lives in the browser's top layer. A toast in
        // the shared page dock would be hidden behind it until close.
        if(pendingSuccessToast){toast(pendingSuccessToast);pendingSuccessToast=null;}
    }
}
async function openDialog(){
    if(visible.value)return;
    restoreFocus=document.activeElement;overflow=document.body.style.overflow;visible.value=true;
    await nextTick();el.value?.showModal();document.body.style.overflow='hidden';
    heading.value?.focus({preventScroll:true});
    void hydrateVenue();

    // Entity-only favorites do not need basketball game catalogs.
    if(!(contextSource.value==='favorite' && contextType.value!=='venue') && !amenities.value.length){
        try{
            const r=await fetch(props.options.endpoints.amenities,{credentials:'same-origin',headers:{Accept:'application/json'}});
            if(r.ok)amenities.value=(await r.json()).data||[];
        }catch{}
    }
}
function beginLogin(){
    if(!validateExpiry())return;
    if(cadence.value==='once'&&dateMode.value==='dates'&&!dates.value.length){error.value='Выбери хотя бы одну дату.';return;}
    if(chosenVenueId.value && similar.value&&(latitude.value===''||longitude.value==='')){error.value='Укажи центр поиска для похожих площадок.';return;}
    const payload=serialize();
    try{sessionStorage.setItem(STORAGE,JSON.stringify({path:location.pathname,createdAt:Date.now(),data:{...payload,venue:{name:selectedVenueName.value,url:selectedVenueUrl.value},excludedVenues:excludedVenues.value,manuallyNamed:manuallyNamed.value}}));}
    catch{error.value='Не удалось сохранить настройки до входа. Проверь настройки браузера.';return;}
    close(true);
    window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true,preferredRole:'player'}}));
}
async function save(payload){
    busy.value=true;error.value='';errors.value={};
    try{
        if(!editingId.value&&alreadySubscribed.value&&hasFavorite.value){
            error.value='Ты уже подписан на эту площадку. Изменить подписку можно в разделе «Мои подписки».';
            return;
        }
        const response=await fetch(editingId.value ? props.options.endpoints.base+'/'+editingId.value : props.options.endpoints.store,{
            method:editingId.value?'PUT':'POST',credentials:'same-origin',
            headers:{'Content-Type':'application/json',Accept:'application/json','X-Requested-With':'XMLHttpRequest',
                'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
            body:JSON.stringify(payload)});
        const result=await response.json().catch(()=>({}));
        if(!response.ok||!result.data?.id){
            errors.value=result.errors||{};
            error.value=response.status===419?'Сессия истекла. Обнови страницу.':
                response.status===409?'Заверши настройку приватности.':
                response.status===422?'Проверь выбранные условия.':
                'Не получилось сохранить. Повтори попытку.';toast('Не удалось сохранить подписку. Попробуй ещё раз.',true);return;
        }
        sessionStorage.removeItem(STORAGE);
        success.value=result.data;
        pendingSuccessToast=editingId.value?'Подписка сохранена':'Подписка оформлена';
        // Subscription is saved independently. Favorite is an optional second
        // operation: failure must not erase the saved subscription or create
        // another one on a retry.
        if(favoriteExtraVisible.value && favoriteSelected.value) {
            favoriteRetryNeeded.value=!await applyFavorite();
        }
        window.dispatchEvent(new CustomEvent('mskba:play-intent:saved',{detail:{id:result.data.id,venueId:result.data.venue_id}}));
    }catch{error.value='Проблема с соединением. Можно повторить попытку без создания дубля.';toast(error.value,true);}
    finally{busy.value=false;}
}
async function submit(){
    if(step.value!=='options')return next();
    error.value='';
    if(!validateExpiry())return;
    if(cadence.value==='once'&&dateMode.value==='dates'&&!dates.value.length){error.value='Выбери хотя бы одну дату.';return;}
    if(!isAuth.value){beginLogin();return;}
    if(chosenVenueId.value && similar.value&&(latitude.value===''||longitude.value==='')){error.value='Укажи центр поиска для похожих площадок.';return;}
    await save(serialize());
}
async function resume(){
    if(onceResume||!isAuth.value||document.querySelector('[data-onboarding-pending]'))return;
    const pending=readPending();if(!pending)return;
    onceResume=true;restore(pending.data);
    step.value='options';await openDialog();void refreshChannels();
}
function authSuccess(){isAuth.value=true;void resume();}
function onClick(e){if(e.target.closest?.('[data-play-intent-open]')){e.preventDefault();
    pendingSuccessToast=null;
    contextSource.value='play';contextType.value='venue';contextId.value=props.options.venueId??null;
    favoriteOpen.value=false;favoriteSelected.value=true;favoriteState.value='unknown';
    favoriteError.value='';alreadySubscribed.value=false;playOpen.value=true;
    favoriteRetryNeeded.value=false;
    editingId.value=null;step.value='form';manuallyNamed.value=false;hasExpiry.value=false;expiryDate.value='';selectedVenueId.value=props.options.venueId??null;venueDisabled.value=false;showOnVenue.value=true;notifyMatches.value=true;notifyOffers.value=true;notifyVenueNews.value=true;gameChannels.value=['in_app'];venueChannels.value=['in_app'];offersChannels.value=['in_app'];similar.value=false;excludedVenues.value=[];venueNotice.value='';selectedVenueName.value=props.options.venueName??'';selectedVenueUrl.value=props.options.venueUrl??'';venueSearch.value=selectedVenueName.value;latitude.value=props.options.venueLatitude??'';longitude.value=props.options.venueLongitude??'';interestName.value=suggestedName.value;requestId=null;success.value=null;void openDialog().then(()=>void syncFavorite());}}
async function loadMatchingVenues(){
    if(!editingId.value || matchingLoading.value)return;
    matchingOpen.value=true;matchingLoading.value=true;matchingError.value='';matchingVenues.value=[];
    try{
        const endpoint=props.options.endpoints.base+'/'+editingId.value+'/recommendations';
        const response=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('Не удалось подобрать площадки. Попробуй ещё раз.');
        matchingVenues.value=(await response.json()).data||[];
    }catch(e){matchingError.value=e.message;}
    finally{matchingLoading.value=false;}
}
function onEdit(event){
    pendingSuccessToast=null;
    const intent=event.detail?.intent;
    if(!intent || !Number.isInteger(intent.id) || intent.id<=0)return;
    matchingOpen.value=false;matchingVenues.value=[];matchingError.value='';
    contextSource.value='edit';contextType.value=intent.venue_id?'venue':null;contextId.value=intent.venue_id??null;
    favoriteOpen.value=false;favoriteSelected.value=true;favoriteState.value='unknown';
    favoriteError.value='';playOpen.value=true;
    editingId.value=intent.id;step.value='form';success.value=null;error.value='';errors.value={};
    restore(intent);
    if(['expired','cancelled'].includes(intent.effective_status)&&intent.cadence==='once'){
        dates.value=[];dateMode.value='dates'; // Old game dates cannot be replayed.
        error.value='Для восстановления разовой подписки выбери новую дату.';
    }
    void openDialog().then(()=>{void hydrateExcluded(intent.excluded_venue_ids);void syncFavorite();});
}
function onBackdrop(e){
    if(e.target!==el.value)return;
    const r=el.value.getBoundingClientRect();
    if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();
}
onMounted(()=>{window.addEventListener('mskba:subscription-dialog:open',contextOpen);window.addEventListener('mskba:contact:updated',refreshChannels);document.addEventListener('click',onClick);window.addEventListener('mskba:play-intent:edit',onEdit);window.addEventListener('mskba:auth:success',authSuccess);window.addEventListener('mskba:onboarding-completed',authSuccess);void resume();});
onBeforeUnmount(()=>{window.removeEventListener('mskba:subscription-dialog:open',contextOpen);window.removeEventListener('mskba:contact:updated',refreshChannels);document.removeEventListener('click',onClick);window.removeEventListener('mskba:play-intent:edit',onEdit);window.removeEventListener('mskba:auth:success',authSuccess);window.removeEventListener('mskba:onboarding-completed',authSuccess);clearTimeout(venueTimer);clearTimeout(excludedTimer);if(el.value?.open)el.value.close();});
</script>
<template>
<Teleport to="body">
<dialog v-if="visible" ref="el" class="mskba-modal app-play-dialog" :data-surface-tone="success?'success':undefined"
 aria-labelledby="app-play-dialog-title" @cancel.prevent="close()" @click="onBackdrop">
    <header class="mskba-modal__header app-play-dialog__header">
        <div><p class="eyebrow accent">{{contextSource==='favorite'?'MSKBA / ИЗБРАННОЕ':'MSKBA / ИГРА'}}</p><h2 id="app-play-dialog-title" ref="heading" tabindex="-1">{{success?(editingId?'Подписка сохранена':'Подписка оформлена'):titleText}}</h2></div>
        <button type="button" class="icon-button" aria-label="Закрыть окно" @click="close()"><svg aria-hidden="true"><use href="#close"/></svg></button>
    </header>
    <template v-if="success">
        <div class="mskba-modal__body mskba-scroll app-play-dialog__body">
            <p class="app-play-dialog__success" role="status">{{editingId?'Изменения подписки сохранены.':'Подписка оформлена! Она не означает запись на игру или бронирование площадки.'}}</p>
            <p class="muted">Изменить параметры и видимость профиля можно в разделе «Мои подписки».</p>
            <div v-if="favoriteRetryNeeded" class="app-play-dialog__context-section">
                <p class="error" role="alert">Подписка сохранена, но не удалось добавить площадку в избранное: {{favoriteError}}</p>
                <button type="button" class="button secondary" :disabled="favoriteBusy" @click="retryFavorite()">
                    {{favoriteBusy?'Сохраняем…':'Повторить добавление в избранное'}}</button>
            </div>
        </div>
        <footer class="mskba-modal__footer app-play-dialog__footer app-play-dialog__footer--success">
            <a class="button primary" :href="options.endpoints.mine">Мои подписки</a>
            <button class="button secondary" type="button" @click="close()">Готово</button>
        </footer>
    </template>
    <template v-else>
        <div v-show="step==='form'" class="mskba-modal__body mskba-scroll app-play-dialog__body">
            <section v-if="contextSource==='favorite'" class="app-play-dialog__context-section">
                <p class="app-play-dialog__context-hint">{{favoriteDescription}}</p>
                <p v-if="favoriteError" class="error" role="alert">{{favoriteError}}</p>
            </section>
            <section v-if="contextualModule==='play' && contextSource==='favorite'" class="app-play-dialog__context-section">
                <label class="check-label"><input v-model="playOpen" type="checkbox" :disabled="alreadySubscribed">
                    Хочу здесь играть</label>
                <p v-if="alreadySubscribed" class="app-play-dialog__context-hint">
                    У тебя уже есть подписка на эту площадку.
                    <a class="button secondary" href="/account/subscriptions?status=active">Смотреть подписки</a></p>
                <p v-else-if="!playOpen" class="app-play-dialog__context-hint">Включи, чтобы задать параметры поиска игр.</p>
            </section>
            <template v-if="supportsPlay && playOpen">
            <div class="app-play-dialog__info">
                <p>Укажи удобное время и формат. MSKBA поможет найти игроков и подходящие игры без обязательств.</p>
                <div v-if="expanded" class="app-play-dialog__info-details mskba-scroll">
                    <p>Настройки можно изменить или приостановить. Участие, бронирование и оплата согласовываются отдельно.</p>
                    <p>Похожие площадки будут подбираться по расстоянию и указанным удобствам. Совпадение не гарантирует свободную бронь.</p>
                    <p>Контакты не передаются владельцам площадок при оформлении подписки.</p>
                    <p v-if="!isAuth" class="app-play-dialog__login-hint">Перед оформлением подписки понадобится войти или зарегистрироваться.</p>
                </div>
                <button type="button" class="app-play-dialog__text-button" :aria-expanded="expanded" @click="expanded=!expanded">{{expanded?'Свернуть':'Подробнее'}}</button>
            </div>
            <form id="app-play-intent-form" class="app-play-dialog__form" @submit.prevent="step==='form'?next():submit()">
                <label class="field-group" for="app-play-intent-name">Название подписки
                    <input id="app-play-intent-name" v-model="interestName" class="app-play-dialog__name-input"
                        name="subscription_name" type="text" maxlength="80" autocomplete="off"
                        @input="manuallyNamed=true" placeholder="Игры по моим условиям">
                </label>
                <div class="app-play-dialog__expiry">
                    <label class="check-label" for="app-play-intent-has-expiry">
                        <input id="app-play-intent-has-expiry" v-model="hasExpiry" type="checkbox">
                        <span>Срок действия</span>
                    </label>
                    <label v-if="hasExpiry" class="field-group app-play-dialog__expiry-date" for="app-play-intent-expiry-date">
                        Дата окончания подписки
                        <input id="app-play-intent-expiry-date" v-model="expiryDate" name="expires_at"
                            type="date" :min="today()" required>
                    </label>
                    <p v-else class="app-play-dialog__hint">Бессрочно</p>
                </div>
                <div class="app-play-dialog__venue-picker field-group">
                    <label class="app-play-dialog__field-label" for="app-play-venue-search">Выбранная площадка</label>
                    <div class="app-play-dialog__venue-picker-header">
                        <span v-if="chosenVenueId" class="app-play-dialog__hint">Поиск по названию, адресу и метро</span>
                        <span v-else class="app-play-dialog__hint">Поиск без привязки к площадке</span>
                        <button v-if="selectedVenueId" type="button" class="app-play-dialog__text-button" @click="unlinkVenue">{{venueDisabled?'Вернуть площадку':'Без конкретной площадки'}}</button>
                    </div>
                    <div class="app-play-dialog__autocomplete">
                        <input id="app-play-venue-search" v-model="venueSearch" class="app-play-dialog__venue-input" type="text" autocomplete="off"
                            role="combobox" aria-controls="app-play-venue-options" aria-autocomplete="list"
                            :aria-expanded="venueMenu" :disabled="venueDisabled"
                            placeholder="Начни вводить название площадки"
                            @focus="searchVenueList('venue')" @input="searchVenueList('venue')"
                            @keydown.esc="venueMenu=false">
                        <div v-if="venueMenu" id="app-play-venue-options" role="listbox" class="app-play-dialog__suggestions">
                            <button v-for="venue in venueSuggestions" :key="venue.id" type="button" role="option"
                                :aria-selected="venue.id===chosenVenueId" @click="chooseVenue(venue)">
                                <strong>{{venue.name}}</strong><span>{{venue.address}}</span>
                            </button>
                            <p v-if="!venueSuggestions.length" class="muted">{{searchingVenue?'Ищем…':'Площадки не найдены'}}</p>
                        </div>
                    </div>
                </div>
                <label class="check-label"><input v-model="similar" type="checkbox" :disabled="!chosenVenueId">
                    <strong>Подбирать похожие площадки</strong>
                </label>
                <div v-if="chosenVenueId && similar" class="app-play-dialog__similar">
                    <label class="field-group">Радиус поиска: {{radius}} км
                        <input v-model.number="radius" type="range" min="1" max="30">
                    </label>
                    <div class="app-play-dialog__venue-picker">
                        <label class="app-play-dialog__field-label" for="app-play-excluded-search">Исключённые площадки</label>
                        <div class="app-play-dialog__autocomplete">
                            <input id="app-play-excluded-search" v-model="excludedSearch" class="app-play-dialog__venue-input" type="text" autocomplete="off"
                                role="combobox" aria-controls="app-play-excluded-options" aria-autocomplete="list"
                                :aria-expanded="excludedMenu" placeholder="Найти площадку в выбранном радиусе"
                                @focus="searchVenueList('excluded')" @input="searchVenueList('excluded')"
                                @keydown.esc="excludedMenu=false">
                            <div v-if="excludedMenu" id="app-play-excluded-options" class="app-play-dialog__suggestions" role="listbox">
                                <button v-for="venue in excludedSuggestions" :key="venue.id" type="button" role="option"
                                    :aria-selected="false" @click="chooseExcluded(venue)">
                                    <strong>{{venue.name}}</strong><span>{{venue.address}}</span>
                                </button>
                                <p v-if="!excludedSuggestions.length" class="muted">В радиусе нет подходящих вариантов</p>
                            </div>
                        </div>
                        <div v-if="excludedVenues.length" class="app-play-dialog__excluded-tags" aria-label="Исключённые площадки">
                            <button v-for="venue in excludedVenues" :key="venue.id" type="button" class="app-play-dialog__excluded-tag"
                                :aria-label="'Убрать исключение: '+venue.name"
                                @click="excludedVenues=excludedVenues.filter(v=>v.id!==venue.id)">
                                {{venue.name}} <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <p v-if="venueNotice" class="app-play-dialog__hint" role="status">{{venueNotice}}</p>
                    </div>
                </div>
                <fieldset><legend>Как часто хочешь играть?</legend>
                    <label v-for="m in [{v:'flexible',t:'Не важно — любые игры'},{v:'once',t:'Разово — одна игра'},{v:'regular',t:'Регулярно'}]" :key="m.v" class="check-label">
                        <input v-model="cadence" type="radio" name="cadence" :value="m.v">{{m.t}}
                    </label>
                </fieldset>
                <fieldset><legend>Дни и время</legend>
                    <template v-if="cadence==='once'">
                        <div class="app-play-dialog__chips">
                            <button type="button" class="chip" :aria-pressed="dateMode==='range'" @click="dateMode='range'">Ближайшие дни</button>
                            <button type="button" class="chip" :aria-pressed="dateMode==='dates'" @click="dateMode='dates'">Конкретные даты</button>
                        </div>
                        <div v-if="dateMode==='range'" class="app-play-dialog__chips">
                            <button v-for="n in ['7','14','30']" :key="n" type="button" class="chip" :aria-pressed="period===n" @click="period=n">{{n}} дней</button>
                        </div>
                        <div v-else>
                            <div class="app-play-dialog__date-row"><label class="field-group">Дата<input v-model="dateInput" type="date" :min="today()" :max="dayAhead(30)"></label><button type="button" class="button secondary" @click="addDate">Добавить</button></div>
                            <div class="app-play-dialog__chips"><button v-for="d in dates" :key="d" class="chip" type="button" @click="dates=dates.filter(x=>x!==d)">{{d}} ×</button></div>
                        </div>
                    </template>
                    <template v-if="cadence!=='once'||dateMode==='range'">
                        <p class="app-play-dialog__field-label">Дни недели</p>
                        <div class="app-play-dialog__chips">
                            <button type="button" class="chip" :aria-pressed="!dayIds.length" @click="dayIds=[]">Не важно</button>
                            <button v-for="d in days" :key="d.id" class="chip" type="button" :aria-pressed="dayIds.includes(d.id)" @click="toggleDay(d.id)">{{d.name}}</button>
                        </div>
                    </template>
                    <div class="app-play-dialog__day-grid">
                        <section v-for="k in keys" :key="k" class="app-play-dialog__day">
                            <strong>{{nameForKey(k)}}</strong>
                            <div v-for="(w,i) in dayWindows(k)" :key="i" class="app-play-dialog__time-entry">
                                <div class="app-play-dialog__chips">
                                    <button v-for="p in parts" :key="p.id" type="button" class="chip" :aria-pressed="w.part===p.id" @click="changePart(w,p.id)">{{p.label}}</button>
                                </div>
                                <div v-if="w.part!=='any'" class="app-play-dialog__time-range">
                                    <label class="field-group">Время начала<span class="select-control"><select v-model="w.from" @change="changeFrom(w)">
                                        <option v-for="o in clockOptions(w.part)" :key="o.v" :value="o.v">{{o.t}}</option>
                                    </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
                                    <label class="field-group">До<span class="select-control"><select v-model="w.to">
                                        <option v-for="o in clockOptions(w.part,w.from)" :key="o.v" :value="o.v">{{o.t}}</option>
                                    </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
                                </div>
                                <p v-if="w.part==='night'" class="caption">Ночь может продолжиться на следующие сутки.</p>
                                <button v-if="dayWindows(k).length>1" type="button" class="app-play-dialog__text-button" @click="dayWindows(k).splice(i,1)">Удалить интервал</button>
                            </div>
                            <button v-if="dayWindows(k).length<4&&!dayWindows(k).some(w=>w.part==='any')" type="button" class="app-play-dialog__text-button" @click="addInterval(k)">+ Добавить интервал</button>
                        </section>
                    </div>
                </fieldset>
                <fieldset><legend>Формат игры</legend><div class="app-play-dialog__chips">
                    <button type="button" class="chip" :aria-pressed="!formats.length" @click="formats=[]">Не важно</button>
                    <button v-for="f in ['1x1','2x2','3x3','4x4','5x5']" :key="f" type="button" class="chip" :aria-pressed="formats.includes(f)" @click="toggleFormat(f)">{{f.replace('x','×')}}</button>
                </div></fieldset>
                <label class="field-group">Продолжительность игры<span class="select-control"><select v-model="duration">
                    <option value="">Не важно</option><option value="60">1 час</option><option value="90">1,5 часа</option><option value="120">2 часа</option><option value="180">3 часа</option>
                </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
                <div v-if="similar || !chosenVenueId" class="app-play-dialog__similar">
                <fieldset><legend>Тип площадки</legend><div class="app-play-dialog__chips">
                    <button v-for="kind in [{v:'any',t:'Не важно'},{v:'outdoor',t:'Открытая'},{v:'indoor',t:'Крытая'}]"
                        :key="kind.v" type="button" class="chip" :aria-pressed="venueKind===kind.v"
                        @click="venueKind=kind.v">{{kind.t}}</button>
                </div></fieldset>
                    <div v-if="chosenVenueId && similar && (!options.venueLatitude||!options.venueLongitude)" class="app-play-dialog__time-range">
                        <label class="field-group">Широта<input v-model="latitude" type="number" min="-90" max="90" step="0.0000001"></label>
                        <label class="field-group">Долгота<input v-model="longitude" type="number" min="-180" max="180" step="0.0000001"></label>
                    </div>
                    <fieldset><legend>Доступ</legend><div class="app-play-dialog__chips">
                        <button v-for="x in [{v:'any',t:'Не важно'},{v:'free',t:'Бесплатно'},{v:'paid',t:'Платно'}]" :key="x.v"
                            type="button" class="chip" :aria-pressed="access===x.v" @click="access=x.v">{{x.t}}</button>
                    </div></fieldset>
                    <fieldset><legend>Удобства площадки</legend>
                        <p class="caption">После выбора укажи: желательно или обязательно.</p>
                        <details v-for="group in groups" :key="group.key" class="app-play-dialog__amenity-group">
                            <summary>{{group.label}} ({{group.items.length}})</summary>
                            <div v-for="a in group.items" :key="a.id" class="app-play-dialog__amenity-row">
                                <label class="check-label"><input type="checkbox" :checked="!!priority[a.id]" @change="priority[a.id]=$event.target.checked?'preferred':''">{{a.name}}</label>
                                <span v-if="priority[a.id]" class="select-control"><select v-model="priority[a.id]" :aria-label="'Приоритет: '+a.name">
                                    <option value="preferred">Желательно</option><option value="required">Обязательно</option>
                                </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span>
                            </div>
                        </details>
                    </fieldset>
                </div>

                <p v-if="error" class="error" role="alert">{{error}}</p>
                <p v-for="(msgs,k) in errors" :key="k" class="error" role="alert">{{Array.isArray(msgs)?msgs[0]:msgs}}</p>
            </form>
            </template>
        </div>
        <div v-show="step==='options' && supportsPlay && playOpen" class="mskba-modal__body mskba-scroll app-play-dialog__body">
            <h3>Настройки подписки</h3>
            <p class="app-play-dialog__hint">Шаг 2 из 2. Проверь настройки перед сохранением подписки.</p>
            <div v-if="favoriteExtraVisible" class="app-play-dialog__context-section">
                <label class="check-label"><input v-model="favoriteSelected" type="checkbox"
                    :disabled="favoriteBusy || favoriteState==='unknown' || favoriteState==='active'">
                    Добавить площадку в избранное</label>
                <p class="app-play-dialog__context-hint" v-if="favoriteState==='active'">
                    Площадка уже в избранном.</p>
                <p class="app-play-dialog__context-hint" v-else>
                    Дополнительное действие. Подписка сохранится независимо от избранного.</p>
                <p v-if="favoriteError" class="error" role="alert">{{favoriteError}}</p>
            </div>
            <div class="app-play-dialog__settings">
                <section class="app-play-dialog__notification-topic" :class="{'app-play-dialog__notification-topic--collapsed':!notifyMatches}">
                    <label class="check-label"><input v-model="notifyMatches" type="checkbox">Уведомлять о подходящих играх</label>
                    <div v-if="notifyMatches" class="app-play-dialog__notification-channels">
                        <strong>Каналы уведомлений</strong>
                        <div v-for="ch in channelList" :key="'game-'+ch.key" class="app-play-dialog__channel">
                            <label class="check-label"><input type="checkbox" :checked="channelChecked('games',ch.key)"
                                :disabled="!channelEnabled(ch.key) || channelsLoading || busy"
                                @change="toggleChannel('games',ch.key,$event.target.checked)">{{ch.label}}</label>
                            <div v-if="ch.key!=='in_app' && !channelEnabled(ch.key)" class="app-play-dialog__channel-hint">
                                <small>{{channelReason(ch.key)}}</small>
                                <button type="button" class="app-play-dialog__text-button" @click="addContact(ch.key)">Добавить {{ch.label}}</button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="app-play-dialog__notification-topic" :class="{'app-play-dialog__notification-topic--collapsed':!notifyVenueNews || !chosenVenueId}">
                    <label class="check-label"><input v-model="notifyVenueNews" type="checkbox" :disabled="!chosenVenueId">
                        Уведомлять о новостях площадки</label>
                    <small v-if="!chosenVenueId" class="app-play-dialog__channel-hint">Для уведомлений о новостях выбери площадку на первом шаге.</small>
                    <div v-if="notifyVenueNews && chosenVenueId" class="app-play-dialog__notification-channels">
                        <strong>Каналы уведомлений</strong>
                        <div v-for="ch in channelList" :key="'venue-'+ch.key" class="app-play-dialog__channel">
                            <label class="check-label"><input type="checkbox" :checked="channelChecked('venue',ch.key)"
                                :disabled="!channelEnabled(ch.key) || channelsLoading || busy"
                                @change="toggleChannel('venue',ch.key,$event.target.checked)">{{ch.label}}</label>
                            <div v-if="ch.key!=='in_app' && !channelEnabled(ch.key)" class="app-play-dialog__channel-hint">
                                <small>{{channelReason(ch.key)}}</small>
                                <button type="button" class="app-play-dialog__text-button" @click="addContact(ch.key)">Добавить {{ch.label}}</button>
                            </div>
                        </div>
                    </div>
                </section>
                <p v-if="channelError" class="error" role="alert">{{channelError}}</p>
                <label class="check-label"><input v-model="showOnVenue" type="checkbox"><span>Показывать другим посетителям мой аватар и игровые характеристики в рамках этого поиска.</span></label>
            </div>
            <div v-if="editingId" class="app-play-dialog__matches">
                <button type="button" class="button secondary" :disabled="matchingLoading"
                    :aria-expanded="matchingOpen" @click="loadMatchingVenues()">
                    {{matchingLoading?'Подбираем площадки…':'Подходящие площадки'}}
                </button>
                <p class="app-play-dialog__hint">Предварительный подбор по сохранённым условиям подписки. Новые настройки вступят в силу после сохранения.</p>
                <p v-if="matchingError" class="error" role="alert">{{matchingError}}</p>
                <p v-if="matchingOpen && !matchingLoading && !matchingError && !matchingVenues.length" class="muted">По выбранным условиям площадки пока не найдены.</p>
                <ul v-if="matchingVenues.length" class="app-play-dialog__matched-venues">
                    <li v-for="venue in matchingVenues" :key="venue.venue_id"><a :href="venue.url" target="_blank" rel="noopener noreferrer">{{venue.name}}</a></li>
                </ul>
            </div>
            <p v-if="error" class="error" role="alert">{{error}}</p>
            <p v-for="(msgs,k) in errors" :key="k" class="error" role="alert">{{Array.isArray(msgs)?msgs[0]:msgs}}</p>
        </div>
        <footer class="mskba-modal__footer app-play-dialog__footer app-play-dialog__steps-footer">
            <template v-if="step==='form'">
                <button class="button secondary" type="button" @click="close()">Отмена</button>
                <button v-if="supportsPlay && playOpen" class="button primary" type="button" :disabled="busy || favoriteBusy" @click="next()">Далее</button>
                <button v-else class="button primary" type="button" :disabled="busy || favoriteBusy"
                    @click="saveFavoriteOnly()">Готово</button>
            </template>
            <template v-else>
                <button class="button secondary" type="button" :disabled="busy" @click="step='form'">Назад</button>
                <button class="button primary" type="button" :disabled="busy" @click="submit()">{{busy?'Подписываем…':editingId?'Сохранить подписку':'Подписаться'}}</button>
            </template>
        </footer>
    </template>
</dialog>
</Teleport>
</template>
