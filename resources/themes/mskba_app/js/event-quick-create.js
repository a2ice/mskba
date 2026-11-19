// Unified event-first creation dialog (venue page and events catalog).
// Schedules are nonbinding preferences. No passwords or private credentials
// are stored; the draft is kept only for this browser tab during auth.
(() => {
    const dialog=document.querySelector('[data-quick-event-dialog]');
    const authNotice=document.querySelector('[data-quick-auth-info]');
    if(!dialog||!authNotice||typeof dialog.showModal!=='function')return;
    const form=dialog.querySelector('[data-quick-event-form]');
    const sections=[...dialog.querySelectorAll('[data-quick-step]')];
    const next=dialog.querySelector('[data-quick-next]');
    const back=dialog.querySelector('[data-quick-back]');
    const submit=dialog.querySelector('[data-quick-submit]');
    const type=form.elements.namedItem('type');
    const title=form.querySelector('[data-quick-title]');
    const venue=form.querySelector('[data-quick-venue]');
    const court=form.querySelector('[data-quick-court]');
    const format=form.querySelector('[data-quick-format]');
    const time=form.querySelector('[data-quick-time]');
    const date=form.querySelector('[data-quick-date]');
    const duration=form.querySelector('[data-quick-duration]');
    const slotsList=form.querySelector('[data-quick-slots]');
    const modeGroup=form.querySelector('[data-quick-mode-group]');
    const noSlots=form.querySelector('[data-quick-no-slots]');
    const availability=form.querySelector('[data-quick-availability]');
    const feedback=form.querySelector('[data-quick-feedback]');
    const storageKey='mskba:event-quick-create:'+location.pathname;
    let step=0,slots=[],editingTitle=false,saving=false;
    let authenticated=dialog.dataset.authenticated==='1';
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const courts=(()=>{try{return JSON.parse(dialog.dataset.courts||'{}');}catch{return {};}})();
    const report=text=>{feedback.textContent=text;feedback.hidden=!text;};
    const clearValidation=field=>{
        field.removeAttribute('aria-invalid');
        const box=field.closest('.quick-event-dialog__field');
        box?.querySelector('.quick-event-dialog__error')?.remove();
    };
    const validate=field=>{
        clearValidation(field);
        if(!field.willValidate||field.checkValidity())return true;
        field.setAttribute('aria-invalid','true');
        const box=field.closest('.quick-event-dialog__field');
        if(box){
            const text=document.createElement('small');
            text.className='quick-event-dialog__error';
            text.textContent=field.validity.valueMissing?'Это поле обязательно.':
                field.validity.tooLong?'Слишком длинное значение.':'Проверьте введённое значение.';
            box.append(text);
        }
        field.focus();return false;
    };
    for(const field of form.querySelectorAll('input,select,textarea')){
        field.addEventListener('input',()=>clearValidation(field));
        field.addEventListener('change',()=>clearValidation(field));
    }
    function setStep(index){
        step=Math.max(0,Math.min(sections.length-1,index));
        sections.forEach((node,i)=>{node.hidden=i!==step;});
        back.hidden=step===0;
        next.hidden=step===sections.length-1;
        submit.hidden=step!==sections.length-1;
        if(step===2)review();
        dialog.querySelector('[data-quick-event-title]')?.focus();
    }
    const syncFormat=()=>{
        const presets={
            streetball_3x3:['3','streetball','whole_game'],
            basketball_5x5:['5','basketball','periods'],
            streetball_1x1:['1','streetball','whole_game'],
        };
        const fields=presets[format.value]||presets.streetball_3x3;
        form.querySelector('[data-quick-side-a]').value=fields[0];
        form.querySelector('[data-quick-side-b]').value=fields[0];
        form.querySelector('[data-quick-scoring]').value=fields[1];
        form.querySelector('[data-quick-timing]').value=fields[2];
    };
    const syncType=()=>{
        form.querySelector('[data-quick-game-fields]').hidden=type.value!=='game';
        if(!editingTitle)title.value=(type.selectedOptions?.[0]?.textContent||'Игра')+' на площадке';
    };
    const syncCourts=()=>{
        const remembered=court.value;
        court.replaceChildren();
        const base=document.createElement('option');base.value='';base.textContent='Основной зал';court.append(base);
        for(const item of (courts[venue.value]||[])){
            const option=document.createElement('option');option.value=String(item.id);option.textContent=item.name;
            court.append(option);
        }
        court.value=Array.from(court.options).some(o=>o.value===remembered)?remembered:'';
        availability.hidden=true;
    };
    type.addEventListener('change',syncType);
    format.addEventListener('change',syncFormat);
    title.addEventListener('input',()=>{editingTitle=true;});
    venue.addEventListener('change',()=>{
        syncCourts();
        if(slots.length){slots=[];renderSlots();}
    });
    court.addEventListener('change',()=>{availability.hidden=true;});
    syncType();syncFormat();syncCourts();
    // Preserve the selected hall when entering the wizard from its own page.
    if(dialog.dataset.initialCourtId && [...court.options].some(option=>option.value===dialog.dataset.initialCourtId)){
        court.value=dialog.dataset.initialCourtId;
    }
    function renderSlots(){
        slotsList.replaceChildren();
        slots.forEach((item,index)=>{
            const row=document.createElement('li');
            const label=document.createElement('span');
            label.textContent=item.starts_at.replace('T',' ')+' · '+item.duration_minutes+' мин.';
            const remove=document.createElement('button');
            remove.type='button';remove.textContent='Убрать';
            remove.className='button secondary';
            remove.addEventListener('click',()=>{slots.splice(index,1);renderSlots();});
            row.append(label,remove);slotsList.append(row);
        });
        modeGroup.hidden=slots.length<2;
        noSlots.hidden=slots.length>0;
        if(slots.length<2){
            const mode=form.querySelector('[name="schedule_mode"][value="alternatives"]');
            if(mode)mode.checked=true;
        }
    }
    function addSlot(){
        if(!validate(venue))return;
        if(!date.value){date.setCustomValidity('Выберите дату.');validate(date);date.setCustomValidity('');return;}
        if(!time.value){time.setCustomValidity('Выберите время.');validate(time);time.setCustomValidity('');return;}
        const item={starts_at:date.value+'T'+time.value,duration_minutes:Number(duration.value)};
        if(slots.some(x=>x.starts_at===item.starts_at&&x.duration_minutes===item.duration_minutes)){
            availability.hidden=false;availability.textContent='Такой интервал уже добавлен.';return;
        }
        if(slots.length>=8){availability.hidden=false;availability.textContent='Можно выбрать до восьми интервалов.';return;}
        slots.push(item);renderSlots();
        availability.hidden=false;availability.textContent='Интервал добавлен. Можешь указать ещё один вариант времени.';
    }
    form.querySelector('[data-quick-add-slot]').addEventListener('click',addSlot);
    async function loadDaySchedule(){
        availability.hidden=false;availability.textContent='Загружаем расписание…';
        if(!venue.value||!date.value){availability.textContent='Сначала выберите площадку и дату.';return;}
        const url=new URL(dialog.dataset.scheduleDayTemplate.replace('__VENUE__',encodeURIComponent(venue.value)),location.href);
        url.searchParams.set('date',date.value);
        if(court.value)url.searchParams.set('venue_court_id',court.value);
        try{
            const res=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
            const data=await res.json().catch(()=>({}));
            if(!res.ok)throw Error(data.message||'Расписание временно недоступно.');
            availability.replaceChildren();
            const items=(data.slots||[]).filter(slot=>slot.starts_at&&slot.ends_at);
            if(!items.length){availability.textContent='На эту дату интервалы пока не опубликованы. Можно вручную указать желаемое время.';return;}
            for(const slot of items){
                const occupied=slot.status!=='available';
                const button=document.createElement('button');button.type='button';
                button.textContent=slot.starts_at+'–'+slot.ends_at+(occupied?' · занято':' · свободно');
                button.classList.toggle('is-busy',occupied);
                button.setAttribute('aria-label','Начало '+slot.starts_at+(occupied?', сейчас занято':', свободно'));
                button.addEventListener('click',()=>{
                    time.value=slot.starts_at;
                    availability.querySelectorAll('button').forEach(b=>b.classList.remove('is-picked'));
                    button.classList.add('is-picked');
                    // Occupied time remains eligible only as a preliminary request.
                });
                availability.append(button);
            }
        }catch(e){availability.textContent=e.message;}
    }
    form.querySelector('[data-quick-show-schedule]').addEventListener('click',()=>void loadDaySchedule());
    function review(){
        const node=form.querySelector('[data-quick-review]');node.replaceChildren();
        const info=document.createElement('p');
        const kind=type.selectedOptions[0]?.textContent||'Мероприятие';
        const place=venue.selectedOptions[0]?.textContent||'Площадка';
        info.textContent=kind+' · '+(title.value.trim()||kind)+' · '+place;
        node.append(info);
        const details=document.createElement('p');
        if(!slots.length)details.textContent='Без даты: создаётся черновик. Позже можно выбрать время и опубликовать мероприятие.';
        else if(slots.length>1&&form.elements.namedItem('schedule_mode').value==='separate')
            details.textContent='Будет создано '+slots.length+' отдельных мероприятий с общими параметрами и разными датами.';
        else details.textContent='Создаётся одно мероприятие; '+slots.length+' вариант(а) времени для согласования.';
        node.append(details);
        if(slots.length){
            const list=document.createElement('ol');
            for(const slot of slots){const li=document.createElement('li');li.textContent=slot.starts_at.replace('T',' ')+' · '+slot.duration_minutes+' мин.';list.append(li);}
            node.append(list);
        }
        report('');
    }
    const baseFields=[type,title,venue,form.elements.namedItem('visibility')];
    const goNext=()=>{
        if(step===0){for(const field of baseFields)if(!validate(field))return;}
        setStep(step+1);
    };
    next.addEventListener('click',goNext);
    back.addEventListener('click',()=>setStep(step-1));
    // Enter validates the current field, then focuses the next field
    // in the current step; the final one advances the wizard.
    form.addEventListener('keydown',event=>{
        if(event.key!=='Enter'||event.target.tagName==='TEXTAREA'||event.isComposing)return;
        const field=event.target;
        if(!field.matches('input:not([type=hidden]),select'))return;
        event.preventDefault();
        if(!validate(field))return;
        const eligible=[...sections[step].querySelectorAll('input:not([type=hidden]),select,textarea')]
            .filter(el=>!el.disabled&&!el.closest('[hidden]')&&el.getClientRects().length);
        const nextField=eligible[eligible.indexOf(field)+1];
        if(nextField){nextField.focus();return;}
        if(step<2)goNext();else form.requestSubmit(submit);
    });
    function values(){
        const data=Object.fromEntries(new FormData(form).entries());
        delete data._token;
        delete data.venue_court_id;
        if(court.value)data.venue_court_id=Number(court.value);
        if(!data.max_participants)delete data.max_participants;
        if(!data.title)data.title=type.selectedOptions[0]?.textContent||'Игра';
        data.slots=slots;
        data.schedule_mode=form.querySelector('[name="schedule_mode"]:checked')?.value||'alternatives';
        return data;
    }
    const save=()=>{try{sessionStorage.setItem(storageKey,JSON.stringify({draft:values(),step,at:Date.now()}));}catch{}};
    const clear=()=>{
        try{
            sessionStorage.removeItem(storageKey);
            if(sessionStorage.getItem('mskba:auth:pending-flow')==='quick-event')
                sessionStorage.removeItem('mskba:auth:pending-flow');
        }catch{}
    };
    function restore(saved){
        if(!saved?.draft)return false;
        for(const [name,value] of Object.entries(saved.draft)){
            if(name==='slots'||name==='schedule_mode')continue;
            const control=form.elements.namedItem(name);
            if(control&&typeof value==='string'&&typeof control.value==='string')control.value=value;
        }
        editingTitle=true;
        syncType();syncFormat();syncCourts();
        const data=saved.draft;
        if(data.venue_court_id)court.value=String(data.venue_court_id);
        slots=Array.isArray(data.slots)?data.slots.slice(0,8):[];
        renderSlots();
        const radio=form.querySelector('[name="schedule_mode"][value="'+(data.schedule_mode==='separate'?'separate':'alternatives')+'"]');
        if(radio)radio.checked=true;
        setStep(Number(saved.step)||2);
        return true;
    }
    async function submitCreation(){
        if(saving)return;
        saving=true;submit.disabled=true;report('Сохраняем мероприятие…');
        try{
            const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{
                'Accept':'application/json','Content-Type':'application/json',
                'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),
            },body:JSON.stringify(values())});
            const result=await response.json().catch(()=>({}));
            if(!response.ok)throw Error(Object.values(result.errors||{}).flat()[0]||result.message||'Не удалось создать мероприятие.');
            if(!result.event_url)throw Error('Сервер не вернул ссылку на мероприятие.');
            clear();
            window.location.assign(result.event_url);
        }catch(e){report(e.message);}
        finally{saving=false;submit.disabled=false;}
    }
    form.addEventListener('submit',event=>{
        event.preventDefault();
        if(step!==2){goNext();return;}
        if(!authenticated){
            save();dialog.close();authNotice.showModal();return;
        }
        void submitCreation();
    });
    document.addEventListener('click',event=>{
        const trigger=event.target.closest?.('[data-quick-event-open]');
        if(!trigger)return;
        event.preventDefault();
        const fromVenue=trigger.dataset.quickEventVenueId;
        if(fromVenue){
            venue.value=fromVenue;syncCourts();
            const fromCourt=trigger.dataset.quickEventCourtId||dialog.dataset.initialCourtId;
            if(fromCourt && [...court.options].some(option=>option.value===fromCourt))court.value=fromCourt;
        }
        document.querySelector('[data-venue-play-dialog][open]')?.close();
        document.querySelector('[data-venue-choice-dialog][open]')?.close();
        if(!dialog.open)dialog.showModal();
        setStep(0);type.focus();
    });
    dialog.querySelectorAll('[data-quick-event-close]').forEach(button=>
        button.addEventListener('click',()=>dialog.close()));
    authNotice.querySelectorAll('[data-quick-auth-close]').forEach(button=>
        button.addEventListener('click',()=>authNotice.close()));
    authNotice.querySelector('[data-quick-auth-login]').addEventListener('click',()=>{
        authNotice.close();
        try{sessionStorage.setItem('mskba:auth:pending-flow','quick-event');}catch{}
        window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
        // The legacy theme uses the established classic auth modal.
        const legacy=document.querySelector('[data-venue-booking-auth], [data-modal-target="auth-entry-classic"]');
        if(legacy&&!document.querySelector('[data-app-auth-root]'))legacy.click();
    });
    function resume(){
        let value=null;
        try{value=JSON.parse(sessionStorage.getItem(storageKey)||'null');}catch{}
        if(!value||Date.now()-value.at>20*60*1000){clear();return;}
        if(!restore(value))return;
        if(!dialog.open)dialog.showModal();
        void submitCreation();
    }
    window.addEventListener('mskba:auth:success',()=>{
        authenticated=true;dialog.dataset.authenticated='1';resume();
    });
    if(authenticated)queueMicrotask(resume);
    for(const modal of [dialog,authNotice]){
        let pointerOutside=false;
        modal.addEventListener('pointerdown',event=>{pointerOutside=event.target===modal;});
        modal.addEventListener('pointerup',event=>{if(pointerOutside&&event.target===modal)modal.close();pointerOutside=false;});
        modal.addEventListener('pointercancel',()=>{pointerOutside=false;});
    }
})();
