// One event-only funnel from the venue page. No rental/favorite logic here.
(() => {
    const dialog=document.querySelector('[data-venue-play-dialog]');
    const authNotice=document.querySelector('[data-venue-play-auth-notice]');
    if(!dialog || !authNotice || typeof dialog.showModal!=='function')return;
    const list=dialog.querySelector('[data-venue-play-step="list"]');
    const detail=dialog.querySelector('[data-venue-play-step="detail"]');
    const listFooter=dialog.querySelector('[data-venue-play-list-footer]');
    const title=dialog.querySelector('[data-venue-play-title]');
    const resumeKey='mskba:venue-play-resume:'+location.pathname;
    let selectedId=null, authenticated=dialog.dataset.authenticated==='1';
    let pendingResponse=null, saving=false;
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const getPane=id=>dialog.querySelector('[data-venue-play-pane="'+Number(id)+'"]');
    const saveResume=()=>{
        try{sessionStorage.setItem(resumeKey,JSON.stringify({id:selectedId,status:pendingResponse,at:Date.now()}));}catch{}
    };
    const clearResume=()=>{try{sessionStorage.removeItem(resumeKey);}catch{}};
    const notice=(pane,message,error=false)=>{
        const node=pane?.querySelector('[data-venue-play-feedback]');
        if(node){node.textContent=message;node.hidden=!message;node.classList.toggle('is-error',error);}
    };
    async function request(url,method='GET',body=null){
        const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{
            'Accept':'application/json','X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':csrf(),...(body===null?{}:{'Content-Type':'application/json'}),
        },...(body===null?{}:{body:JSON.stringify(body)})});
        const payload=await response.json().catch(()=>({}));
        if(!response.ok){const error=new Error(payload.message||'Не удалось выполнить действие.');error.status=response.status;throw error;}
        return payload;
    }
    const selectedPane=()=>getPane(selectedId);
    const paintButtons=pane=>{
        const status=pane.dataset.eventStatus||'';
        const choices=[...pane.querySelectorAll('[data-venue-play-rsvp]')];
        if(!choices.length)return;
        // The main CTA itself reverses "Пойду" <-> "Не пойду" for an
        // already confirmed participant. Never show two identical buttons.
        const [main,thinking,decline]=choices;
        main.dataset.venuePlayRsvp=status==='confirmed'?'left':'confirmed';
        main.textContent=status==='confirmed'?'Не пойду':'Пойду';
        thinking.dataset.venuePlayRsvp='tentative';
        decline.dataset.venuePlayRsvp='left';
        decline.hidden=status==='confirmed'||status==='left';
        choices.forEach(button=>{
            const own=button.dataset.venuePlayRsvp===status;
            button.classList.toggle('is-selected',own);
            button.setAttribute('aria-pressed',String(own));
        });
    };
    const appendEntries=(pane,entries)=>{
        const list=pane.querySelector('[data-venue-play-messages]');
        for(const entry of entries){
            if(list.querySelector('[data-entry-id="'+Number(entry.id)+'"]'))continue;
            const item=document.createElement('li');item.dataset.entryId=String(entry.id);
            item.classList.toggle('is-system',!!entry.system);
            item.classList.toggle('is-deleted',!!entry.deleted);
            const label=document.createElement('strong');label.textContent=entry.system?'Событие':(entry.actor||'Участник');
            const time=document.createElement('time');time.textContent=entry.date||'';
            const body=document.createElement('p');body.textContent=entry.text||'';
            item.append(label,time,body);list.append(item);
        }
        const empty=pane.querySelector('[data-venue-play-wall-empty]');
        empty.hidden=list.childElementCount>0;
        if(!empty.hidden)empty.textContent='Сообщений пока нет.';
    };
    async function refreshWall(pane){
        try{
            const response=await request(pane.dataset.eventWallUrl);
            pane.querySelector('[data-venue-play-messages]').replaceChildren();
            appendEntries(pane,response.entries||[]);
            const form=pane.querySelector('[data-venue-play-message-form]');
            form.hidden=!response.can_post;
            pane.querySelector('[data-venue-play-comment-notice]').hidden=!!response.can_post;
        }catch(e){pane.querySelector('[data-venue-play-wall-empty]').textContent=e.message;}
    }
    function showList(){
        selectedId=null;list.hidden=false;detail.hidden=true;listFooter.hidden=false;
        title.textContent='Хочу здесь играть';
    }
    function showEvent(id){
        const pane=getPane(id);if(!pane)return;
        selectedId=Number(id);list.hidden=true;detail.hidden=false;listFooter.hidden=true;
        detail.querySelectorAll('[data-venue-play-pane]').forEach(el=>{el.hidden=el!==pane;});
        title.textContent='Мероприятие';
        paintButtons(pane);
        void refreshWall(pane);
    }
    function open(){
        if(!dialog.open)dialog.showModal();
        if(selectedId)showEvent(selectedId);else showList();
    }
    function guestResponse(status){
        pendingResponse=status;saveResume();dialog.close();
        authNotice.showModal();
    }
    async function respond(pane,status){
        if(saving)return;
        if(!authenticated){guestResponse(status);return;}
        saving=true;pane.querySelectorAll('[data-venue-play-rsvp]').forEach(b=>b.disabled=true);
        notice(pane,'');
        try{
            const result=await request(pane.dataset.eventParticipationUrl,'PATCH',{status});
            pane.dataset.eventStatus=result.status;paintButtons(pane);
            await refreshWall(pane);
            notice(pane,'Ответ на участие сохранён.');
            pendingResponse=null;clearResume();
        }catch(e){notice(pane,e.message,true);}
        finally{saving=false;pane.querySelectorAll('[data-venue-play-rsvp]').forEach(b=>b.disabled=false);}
    }
    document.addEventListener('click',event=>{
        const opener=event.target.closest?.('[data-venue-play-open]');
        if(!opener||opener.disabled)return;
        event.preventDefault();open();
    });
    dialog.querySelectorAll('[data-venue-play-preview]').forEach(button=>
        button.addEventListener('click',()=>showEvent(button.dataset.venuePlayPreview)));
    dialog.querySelector('[data-venue-play-back]')?.addEventListener('click',showList);
    dialog.querySelectorAll('[data-venue-play-close]').forEach(button=>
        button.addEventListener('click',()=>dialog.close()));
    dialog.querySelectorAll('[data-venue-play-rsvp]').forEach(button=>button.addEventListener('click',()=>{
        const pane=button.closest('[data-venue-play-pane]');
        void respond(pane,button.dataset.venuePlayRsvp);
    }));
    for(const pane of dialog.querySelectorAll('[data-venue-play-pane]')){
        const form=pane.querySelector('[data-venue-play-message-form]');
        const field=form.querySelector('[name="body"]');
        field.addEventListener('input',()=>{pane.querySelector('[data-venue-play-chars]').textContent=String([...field.value].length);});
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            if(!authenticated){guestResponse(null);return;}
            const body=field.value.trim();if(!body||[...body].length>200)return;
            const submit=form.querySelector('[type="submit"]');submit.disabled=true;
            try{
                const response=await request(pane.dataset.eventPostUrl,'POST',{
                    body,client_request_id:crypto.randomUUID(),
                });
                field.value='';pane.querySelector('[data-venue-play-chars]').textContent='0';
                await refreshWall(pane);notice(pane,'Сообщение отправлено.');
            }catch(e){notice(pane,e.message,true);}
            finally{submit.disabled=false;}
        });
    }
    authNotice.querySelectorAll('[data-venue-play-auth-close]').forEach(button=>
        button.addEventListener('click',()=>authNotice.close()));
    authNotice.querySelector('[data-venue-play-auth-login]')?.addEventListener('click',()=>{
        authNotice.close();
        window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
    });
    const resume=()=>{
        try{if(sessionStorage.getItem('mskba:auth:pending-flow')==='quick-event')return;}catch{}
        let saved=null;try{saved=JSON.parse(sessionStorage.getItem(resumeKey)||'null');}catch{}
        if(!saved || Date.now()-saved.at>20*60*1000){clearResume();return;}
        if(!getPane(saved.id))return;
        selectedId=Number(saved.id);pendingResponse=saved.status||null;
        open();
        if(pendingResponse){const status=pendingResponse;pendingResponse=null;void respond(selectedPane(),status);}
        else clearResume();
    };
    window.addEventListener('mskba:auth:success',()=>{authenticated=true;dialog.dataset.authenticated='1';resume();});
    if(authenticated)queueMicrotask(resume);
    // A gesture must both begin and end on the backdrop to dismiss.
    for(const modal of [dialog,authNotice]){
        let outsidePointer=null;
        modal.addEventListener('pointerdown',event=>{
            outsidePointer=event.target===modal?event.pointerId:null;
        });
        modal.addEventListener('pointerup',event=>{
            if(outsidePointer===event.pointerId&&event.target===modal)modal.close();
            outsidePointer=null;
        });
        modal.addEventListener('pointercancel',()=>{outsidePointer=null;});
    }
})();
