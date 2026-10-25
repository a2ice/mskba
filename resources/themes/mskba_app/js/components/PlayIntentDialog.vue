<script setup>
import { computed, onMounted, onBeforeUnmount, nextTick, reactive, ref } from 'vue';
const props = defineProps({ options: { type:Object, required:true } });
const STORAGE = 'mskba:play-intent:pending:v1';
const visible=ref(false), el=ref(null), heading=ref(null), expanded=ref(false);
const busy=ref(false), success=ref(null), error=ref(''), errors=ref({});
const interestName=ref('');
const cadence=ref('flexible'), dayIds=ref([]), dates=ref([]), period=ref('14'), dateMode=ref('range'), dateInput=ref('');
const formats=ref([]), duration=ref(''), similar=ref(false), radius=ref(10), access=ref('any'), venueKind=ref('any');
const editingId=ref(null), selectedVenueId=ref(props.options.venueId ?? null), venueDisabled=ref(false);
const selectedVenueName=ref(props.options.venueName ?? ''), selectedVenueUrl=ref(props.options.venueUrl ?? '');
const chosenVenueId=computed(() => venueDisabled.value ? null : selectedVenueId.value);
const latitude=ref(props.options.venueLatitude ?? ''), longitude=ref(props.options.venueLongitude ?? '');
const notifyMatches=ref(true), notifyOffers=ref(true), showOnVenue=ref(false), amenities=ref([]), priority=reactive({});
const favoriteDesired=ref(true), favoriteState=ref('unknown'), favoriteCount=ref(Number(props.options.favoriteCount ?? 0));
const favoriteLoading=ref(false), favoriteError=ref(''), favoriteRetryId=ref(null);
const favoriteEnabled=computed(() => Boolean(chosenVenueId.value && props.options.endpoints?.favoriteStatuses && props.options.endpoints?.favoriteToggle));
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
        include_similar_venues:Boolean(chosenVenueId.value && similar.value),cadence:cadence.value,
        weekdays:cadence.value==='once'&&dateMode.value==='dates'?[]:dayIds.value,
        dates:cadence.value==='once'&&dateMode.value==='dates'?dates.value:[],
        windows:out,formats:formats.value,duration_minutes:duration.value?Number(duration.value):null,
        access_mode:access.value,venue_kind:(!chosenVenueId.value || similar.value)?venueKind.value:'any',amenity_preferences:Object.entries(priority).filter(x=>x[1]).map(x=>({amenity_id:Number(x[0]),priority:x[1]})),
        notify_matches:notifyMatches.value,notify_offers:notifyOffers.value,
        show_on_venue:Boolean(showOnVenue.value),
    };
    if(cadence.value==='once'&&dateMode.value==='range'){
        payload.period_from=today();payload.period_until=dayAhead(Number(period.value)-1);
    }
    if(chosenVenueId.value && similar.value){payload.center_latitude=Number(latitude.value);payload.center_longitude=Number(longitude.value);payload.radius_km=Number(radius.value);}
    return payload;
}
function restore(data){
    interestName.value=data.name || '';
    selectedVenueId.value=data.venue_id ?? null; venueDisabled.value=false;
    showOnVenue.value=Boolean(data.show_on_venue);
    selectedVenueName.value=data.venue?.name ?? (selectedVenueId.value && selectedVenueId.value === props.options.venueId ? props.options.venueName : '') ?? '';
    selectedVenueUrl.value=data.venue?.url ?? (selectedVenueId.value && selectedVenueId.value === props.options.venueId ? props.options.venueUrl : '') ?? '';
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
    for(const item of data.amenity_preferences||[])priority[item.amenity_id]=item.priority;
    for(const key of Object.keys(windows))delete windows[key];
    for(const w of data.windows||[]){
        const key=w.date||w.weekday||'all';
        const part=w.end_next_day?'night':minute(w.start_time)<720?'morning':minute(w.start_time)<1080?'day':'evening';
        if(!windows[key])windows[key]=[];
        windows[key].push({part,from:w.start_time,to:w.end_time});
    }
}
function unlinkVenue(){
    // Preserve the anchor and allow restoring it before saving.
    venueDisabled.value=!venueDisabled.value;
    if(venueDisabled.value) similar.value=false;
}
// Always read current state before a toggle: the endpoint is not idempotent.
async function favoriteStatus(id) {
    const url=new URL(props.options.endpoints.favoriteStatuses, location.origin);
    url.searchParams.append('ids[]',String(id));
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!response.ok)throw new Error('Не удалось проверить избранное.');
    const data=await response.json();
    return {state:data.data?.[id]?.state || 'none',count:Number(data.counts?.[id] ?? 0)};
}
async function loadFavoriteStatus(){
    if(!favoriteEnabled.value)return;
    const id=Number(chosenVenueId.value);
    favoriteLoading.value=true;
    try {
        const status=await favoriteStatus(id);
        if(Number(chosenVenueId.value)===id){
            favoriteState.value=status.state;favoriteCount.value=status.count;
        }
    }catch{
        // The save path rechecks the server and never assumes the venue is absent.
        if(Number(chosenVenueId.value)===id)favoriteState.value='unknown';
    }finally{favoriteLoading.value=false;}
}
async function syncFavorite(id,desired){
    const current=await favoriteStatus(id);
    let state=current.state,count=current.count;
    if((state==='active')!==desired){
        const response=await fetch(props.options.endpoints.favoriteToggle,{
            method:'POST',credentials:'same-origin',
            headers:{'Content-Type':'application/json',Accept:'application/json',
                'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
            body:JSON.stringify({type:'venue',id}),
        });
        if(!response.ok)throw new Error('Не удалось обновить избранное.');
        const result=await response.json();
        state=result.state;count=Number(result.count ?? count);
        if((state==='active')!==desired)throw new Error('Не удалось подтвердить изменение избранного.');
    }
    favoriteState.value=state;favoriteCount.value=count;
    window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',{detail:{venueId:id,state,count}}));
}
async function retryFavorite(){
    if(!favoriteRetryId.value||busy.value)return;
    busy.value=true;
    try {
        await syncFavorite(favoriteRetryId.value,favoriteDesired.value);
        favoriteRetryId.value=null;favoriteError.value='';
    }catch{favoriteError.value='Не удалось изменить избранное. Повтори попытку.';}
    finally{busy.value=false;}
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
    if(!forAuth)restoreFocus?.focus?.({preventScroll:true});
}
async function openDialog(){
    if(visible.value)return;
    restoreFocus=document.activeElement;overflow=document.body.style.overflow;visible.value=true;
    await nextTick();el.value?.showModal();document.body.style.overflow='hidden';
    heading.value?.focus({preventScroll:true});
    if(favoriteEnabled.value)void loadFavoriteStatus();
    if(!amenities.value.length){
        try{
            const r=await fetch(props.options.endpoints.amenities,{credentials:'same-origin',headers:{Accept:'application/json'}});
            if(r.ok)amenities.value=(await r.json()).data||[];
        }catch{}
    }
}
function beginLogin(){
    if(!showOnVenue.value){error.value='Чтобы другие игроки могли найти тебя, подтверди публичность имени, аватара и игровых характеристик в этом поиске.';return;}
    if(cadence.value==='once'&&dateMode.value==='dates'&&!dates.value.length){error.value='Выбери хотя бы одну дату.';return;}
    if(chosenVenueId.value && similar.value&&(latitude.value===''||longitude.value==='')){error.value='Укажи центр поиска для похожих площадок.';return;}
    const payload=serialize();
    try{sessionStorage.setItem(STORAGE,JSON.stringify({path:location.pathname,createdAt:Date.now(),data:payload,favoriteSelected:favoriteDesired.value}));}
    catch{error.value='Не удалось сохранить настройки до входа. Проверь настройки браузера.';return;}
    close(true);
    window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true,preferredRole:'player'}}));
}
async function save(payload,desiredFavorite=favoriteDesired.value){
    busy.value=true;error.value='';errors.value={};favoriteError.value='';favoriteRetryId.value=null;
    try{
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
                'Не получилось сохранить. Повтори попытку.';return;
        }
        sessionStorage.removeItem(STORAGE);
        // Favorite changes are separate from interest creation and must happen only
        // after the interest is saved. Failures cannot create duplicate interests.
        if(payload.venue_id && props.options.endpoints?.favoriteStatuses && props.options.endpoints?.favoriteToggle){
            try { await syncFavorite(Number(payload.venue_id),Boolean(desiredFavorite)); }
            catch {
                favoriteRetryId.value=Number(payload.venue_id);
                favoriteError.value='Интерес сохранён, но избранное не обновилось. Можно повторить отдельно.';
            }
        }
        success.value=result.data;
        window.dispatchEvent(new CustomEvent('mskba:play-intent:saved',{detail:{id:result.data.id,venueId:result.data.venue_id}}));
    }catch{error.value='Проблема с соединением. Можно повторить попытку без создания дубля.';}
    finally{busy.value=false;}
}
async function submit(){
    if(!showOnVenue.value){error.value='Чтобы другие игроки могли найти тебя, подтверди публичность имени, аватара и игровых характеристик в этом поиске.';return;}
    if(cadence.value==='once'&&dateMode.value==='dates'&&!dates.value.length){error.value='Выбери хотя бы одну дату.';return;}
    if(!isAuth.value){beginLogin();return;}
    if(chosenVenueId.value && similar.value&&(latitude.value===''||longitude.value==='')){error.value='Укажи центр поиска для похожих площадок.';return;}
    await save(serialize());
}
async function resume(){
    if(onceResume||!isAuth.value||document.querySelector('[data-onboarding-pending]'))return;
    const pending=readPending();if(!pending)return;
    onceResume=true;restore(pending.data);favoriteDesired.value=pending.favoriteSelected!==false;
    await openDialog();await save(pending.data,favoriteDesired.value);
}
function authSuccess(){isAuth.value=true;void resume();}
function onClick(e){if(e.target.closest?.('[data-play-intent-open]')){e.preventDefault();editingId.value=null;interestName.value='';selectedVenueId.value=props.options.venueId??null;venueDisabled.value=false;showOnVenue.value=false;notifyOffers.value=true;favoriteDesired.value=true;favoriteState.value='unknown';favoriteCount.value=Number(props.options.favoriteCount??0);favoriteError.value='';favoriteRetryId.value=null;selectedVenueName.value=props.options.venueName??'';selectedVenueUrl.value=props.options.venueUrl??'';requestId=null;success.value=null;void openDialog();}}
function onEdit(event){
    const intent=event.detail?.intent;
    if(!intent || !Number.isInteger(intent.id) || intent.id<=0)return;
    editingId.value=intent.id;success.value=null;error.value='';errors.value={};favoriteDesired.value=true;favoriteState.value='unknown';favoriteError.value='';favoriteRetryId.value=null;
    restore(intent);void openDialog();
}
function onBackdrop(e){
    if(e.target!==el.value)return;
    const r=el.value.getBoundingClientRect();
    if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();
}
onMounted(()=>{document.addEventListener('click',onClick);window.addEventListener('mskba:play-intent:edit',onEdit);window.addEventListener('mskba:auth:success',authSuccess);void resume();});
onBeforeUnmount(()=>{document.removeEventListener('click',onClick);window.removeEventListener('mskba:play-intent:edit',onEdit);window.removeEventListener('mskba:auth:success',authSuccess);if(el.value?.open)el.value.close();});
</script>
<template>
<Teleport to="body">
<dialog v-if="visible" ref="el" class="mskba-modal app-play-dialog" :data-surface-tone="success?'success':undefined"
 aria-labelledby="app-play-dialog-title" @cancel.prevent="close()" @click="onBackdrop">
    <header class="mskba-modal__header app-play-dialog__header">
        <div><p class="eyebrow accent">MSKBA / ИГРА</p><h2 id="app-play-dialog-title" ref="heading" tabindex="-1">{{success?'Настройки приняты':editingId?'Редактировать интерес':'Хочу здесь играть'}}</h2></div>
        <button type="button" class="icon-button" aria-label="Закрыть окно" @click="close()"><svg aria-hidden="true"><use href="#close"/></svg></button>
    </header>
    <template v-if="success">
        <div class="mskba-modal__body mskba-scroll app-play-dialog__body">
            <p class="app-play-dialog__success" role="status">Твой интерес сохранён. Это пока не регистрация на игру и не бронирование.</p>
            <p class="muted">Изменить настройки можно в разделе «Мои интересы».</p>
            <p v-if="favoriteError" class="error" role="alert">{{favoriteError}}</p>
            <button v-if="favoriteRetryId" type="button" class="button secondary" :disabled="busy" @click="retryFavorite">{{busy?'Повторяем…':'Повторить изменение избранного'}}</button>
        </div>
        <footer class="mskba-modal__footer app-play-dialog__footer app-play-dialog__footer--success">
            <a class="button primary" :href="options.endpoints.mine">Мои интересы</a>
            <button class="button secondary" type="button" @click="close()">Готово</button>
        </footer>
    </template>
    <template v-else>
        <div class="mskba-modal__body mskba-scroll app-play-dialog__body">
            <div class="app-play-dialog__info">
                <p>Укажи удобное время и формат. MSKBA поможет найти игроков и подходящие игры без обязательств.</p>
                <div v-if="expanded" class="app-play-dialog__info-details mskba-scroll">
                    <p>Настройки можно изменить или приостановить. Участие, бронирование и оплата согласовываются отдельно.</p>
                    <p>Похожие площадки будут подбираться по расстоянию и указанным удобствам. Совпадение не гарантирует свободную бронь.</p>
                    <p>Контакты не передаются владельцам площадок при обычном сохранении интереса.</p>
                    <p v-if="!isAuth" class="app-play-dialog__login-hint">Для сохранения необходимо войти. <button class="app-play-dialog__text-button" type="button" @click="beginLogin()">Войти или зарегистрироваться</button></p>
                </div>
                <button type="button" class="app-play-dialog__text-button" :aria-expanded="expanded" @click="expanded=!expanded">{{expanded?'Свернуть':'Подробнее'}}</button>
            </div>
            <p v-if="selectedVenueId" class="app-play-dialog__chosen-venue" :class="{ 'is-inactive': venueDisabled }">
                <span>Выбранная площадка:</span>
                <a v-if="selectedVenueUrl" :href="selectedVenueUrl">{{selectedVenueName || 'Открыть площадку'}}</a>
                <strong v-else>{{selectedVenueName || 'Площадка №'+selectedVenueId}}</strong>
                <button type="button" class="app-play-dialog__unlink-venue"
                    :aria-label="venueDisabled ? 'Вернуть площадку в интерес' : 'Отвязать площадку от интереса'"
                    :title="venueDisabled ? 'Вернуть площадку' : 'Искать без привязки к площадке'"
                    @click="unlinkVenue">{{ venueDisabled ? '✓' : '×' }}</button>
            </p>
            <p v-else class="app-play-dialog__chosen-venue">Поиск без привязки к конкретной площадке.</p>
            <p v-if="venueDisabled" class="app-play-dialog__hint">После сохранения поиск будет по выбранным условиям, без привязки к площадке.</p>
            <form id="app-play-intent-form" class="app-play-dialog__form" @submit.prevent="submit">
                <label class="field-group" for="app-play-intent-name">Название интереса
                    <input id="app-play-intent-name" v-model="interestName" class="app-play-dialog__name-input"
                        name="interest_name" type="text" maxlength="80" autocomplete="off"
                        placeholder="Например, стритбол по выходным">
                    <span class="caption">Необязательно. Только для твоего удобства, до 80 символов.</span>
                </label>
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
                <label v-if="chosenVenueId" class="check-label"><input v-model="similar" type="checkbox"><strong>Подбирать похожие площадки</strong></label>
                <div v-if="similar || !chosenVenueId" class="app-play-dialog__similar">
                    <label v-if="chosenVenueId && similar" class="field-group">Радиус: {{radius}} км<input v-model.number="radius" type="range" min="1" max="30"></label>
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
                <div class="app-play-dialog__notify">
                    <label class="check-label"><input v-model="notifyMatches" type="checkbox">Уведомлять о подходящих играх</label>
                    <label class="check-label"><input v-model="notifyOffers" type="checkbox">Получать спецпредложения площадок (необязательно)</label>
                </div>
                <label v-if="favoriteEnabled" class="check-label app-play-dialog__favorite-choice">
                    <input v-model="favoriteDesired" type="checkbox" :disabled="busy">
                    <span>{{favoriteDesired?(favoriteState==='active'?'Оставить площадку в избранном':'Добавить площадку в избранное'):(favoriteState==='active'?'Убрать площадку из избранного':'Не добавлять площадку в избранное')}}</span>
                    <small class="app-play-dialog__favorite-count" :aria-busy="favoriteLoading">В избранном: {{favoriteLoading?'…':favoriteCount}}</small>
                </label>
                <label class="check-label app-play-dialog__public-intent"><input v-model="showOnVenue" type="checkbox" required><span>Согласен показывать другим посетителям мой никнейм, аватар и игровые характеристики в рамках этого поиска. Это разрешение действует для выбранной площадки или подходящих вариантов, даже если общая видимость профиля ограничена. Отозвать можно, отменив интерес.</span></label>
                <p v-if="error" class="error" role="alert">{{error}}</p>
                <p v-for="(msgs,k) in errors" :key="k" class="error" role="alert">{{Array.isArray(msgs)?msgs[0]:msgs}}</p>
            </form>
        </div>
        <footer class="mskba-modal__footer app-play-dialog__footer">
            <button class="button primary full" type="submit" form="app-play-intent-form" :disabled="busy">
                {{busy?'Сохраняем…':isAuth?(editingId?'Сохранить изменения':'Сохранить интерес'):'Войти и сохранить'}}</button>
        </footer>
    </template>
</dialog>
</Teleport>
</template>
