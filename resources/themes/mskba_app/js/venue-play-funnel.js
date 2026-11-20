// One event-only funnel from the venue page. No rental/favorite logic here.
(() => {
    const dialog=document.querySelector('[data-venue-play-dialog]');
    const authNotice=document.querySelector('[data-venue-play-auth-notice]');
    if(!dialog || !authNotice || typeof dialog.showModal!=='function')return;
    const list=dialog.querySelector('[data-venue-play-step="list"]');
    const detail=dialog.querySelector('[data-venue-play-step="detail"]');
    const listFooter=dialog.querySelector('[data-venue-play-list-footer]');
    const detailFooter=dialog.querySelector('[data-venue-play-detail-footer]');
    const title=dialog.querySelector('[data-venue-play-title]');
    const resumeKey='mskba:venue-play-resume:'+location.pathname;
    let selectedId=null, authenticated=dialog.dataset.authenticated==='1';
    let pendingResponse=null, saving=false, savingPane=null;
    const rosterLoads=new WeakMap();
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
    const toast=(message,failed=false)=>window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message,failed,dialog}}));
    async function request(url,method='GET',body=null){
        const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{
            'Accept':'application/json','X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':csrf(),...(body===null?{}:{'Content-Type':'application/json'}),
        },...(body===null?{}:{body:JSON.stringify(body)})});
        const payload=await response.json().catch(()=>({}));
        if(!response.ok){const message=response.status>=500?'Не удалось выполнить действие. Попробуйте ещё раз.':(Object.values(payload.errors||{}).flat()[0]||payload.message||'Не удалось выполнить действие.');const error=new Error(message);error.status=response.status;throw error;}
        return payload;
    }
    const selectedPane=()=>getPane(selectedId);
    const paintButtons=pane=>{
        const status=pane.dataset.eventStatus||'';
        const choices=[...pane.querySelectorAll('[data-venue-play-rsvp]')];
        if(!choices.length)return;
        const active=status==='confirmed'||status==='tentative';
        const decline=choices.find(button=>button.dataset.venuePlayRsvp==='left');
        decline.hidden=!active;
        choices.forEach(button=>{
            const own=button.dataset.venuePlayRsvp===status;
            button.classList.toggle('is-selected',own);
            if(dialog.classList.contains('venue-play-dialog--app')){
                button.classList.toggle('primary',own);button.classList.toggle('secondary',!own);
            }else{
                button.classList.toggle('btn--primary',own);button.classList.toggle('btn--secondary',!own);
            }
            button.setAttribute('aria-pressed',String(own));
        });
    };
    const icon=(id)=>{
        if(!document.getElementById(id)){const fallback=document.createElement('i');fallback.className='ti ti-'+(id==='star'?'star-filled':'user');fallback.setAttribute('aria-hidden','true');return fallback;}
        const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');
        const use=document.createElementNS('http://www.w3.org/2000/svg','use');use.setAttribute('href','#'+id);svg.append(use);return svg;
    };
    const rosterBusy=(pane,busy)=>{
        pane.querySelector('[data-venue-play-roster-surface]').setAttribute('aria-busy',String(busy));
        pane.querySelector('[data-venue-play-roster-loading]').hidden=!busy;
        pane.querySelectorAll('[data-venue-play-rsvp]').forEach(button=>button.disabled=busy);
    };
    function paintRoster(pane,roster,scrollToOwn=false,counts=null){
        if(!roster||!Array.isArray(roster.members))throw Error('Не удалось получить актуальный список участников.');
        const list=pane.querySelector('[data-venue-play-participants]');
        const previousScroll=list.scrollTop;
        const fragment=document.createDocumentFragment();
        let ownRow=null;
        for(const member of roster.members){
            const row=document.createElement('li');row.className='venue-play-dialog__participant';
            const avatar=document.createElement('span');avatar.className='venue-play-dialog__avatar';
            if(member.avatar_url){
                const image=document.createElement('img');image.src=member.avatar_url;image.alt='';image.loading='lazy';
                image.addEventListener('error',()=>avatar.replaceChildren(icon('user')),{once:true});avatar.append(image);
            }else{avatar.append(icon('user'));}
            const dot=document.createElement('span');dot.className='venue-play-dialog__participant-status';
            dot.classList.toggle('is-confirmed',member.status==='confirmed');
            dot.classList.toggle('is-declined',member.status==='left');
            const status=member.status==='confirmed'?'Идёт':member.status==='left'?'Не идёт':'Думает';
            dot.title=status+(member.decision_at?': '+member.decision_at:'');
            dot.setAttribute('aria-label',dot.title);dot.setAttribute('data-tooltip-visual','');dot.tabIndex=0;
            const name=document.createElement(member.url?'a':'span');name.className='venue-play-dialog__participant-name';
            if(member.url)name.href=member.url;
            const full=String(member.name||'Участник'),chars=[...full];
            name.textContent=chars.length>20?chars.slice(0,20).join('')+'...':full;
            if(chars.length>20)name.title=full;
            row.append(avatar,dot,name);
            if(member.organizer){
                const star=document.createElement('span');star.className='venue-play-dialog__participant-star';
                star.title='Организатор';star.setAttribute('aria-label','Организатор');star.setAttribute('data-tooltip-visual','');star.tabIndex=0;
                star.append(icon('star'));row.append(star);
            }
            if(member.is_viewer)ownRow=row;
            fragment.append(row);
        }
        list.replaceChildren(fragment);
        const empty=pane.querySelector('[data-venue-play-roster-empty]');
        empty.hidden=roster.members.length>0;empty.textContent='Участников пока нет.';
        pane.querySelector('[data-venue-play-roster-retry]').hidden=true;
        pane.querySelector('[data-venue-play-roster-heading]').textContent='Участники ('+roster.members.filter(member=>member.status!=='left').length+(roster.max_participants!=null?'/'+roster.max_participants:'')+')';
        pane.dataset.eventStatus=roster.viewer_status||'';
        pane.dataset.eventOrganizer=roster.viewer_is_organizer?'1':'0';
        paintButtons(pane);
        const card=dialog.querySelector('[data-venue-play-preview="'+Number(pane.dataset.venuePlayPane)+'"] [data-venue-play-card-count]');
        if(card&&counts&&Number.isInteger(counts.confirmed))card.textContent=counts.confirmed+' пойдут · Подробнее';
        list.scrollTop=previousScroll;
        if(scrollToOwn&&ownRow){
            const rowRect=ownRow.getBoundingClientRect(),listRect=list.getBoundingClientRect();
            if(rowRect.top<listRect.top)list.scrollTop+=rowRect.top-listRect.top;
            else if(rowRect.bottom>listRect.bottom)list.scrollTop+=rowRect.bottom-listRect.bottom;
        }
    }
    const rosterFailure=(pane,message)=>{
        const empty=pane.querySelector('[data-venue-play-roster-empty]');
        if(!pane.querySelector('[data-venue-play-participants]').children.length){empty.hidden=false;empty.textContent=message;}
        pane.querySelector('[data-venue-play-roster-retry]').hidden=false;
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
            paintWall(pane,response);
        }catch(e){pane.querySelector('[data-venue-play-wall-empty]').textContent=e.message;}
    }
    function paintWall(pane,response){
        pane.querySelector('[data-venue-play-messages]').replaceChildren();
        appendEntries(pane,response.entries||[]);
        const form=pane.querySelector('[data-venue-play-message-form]');
        form.hidden=!response.can_post;
        pane.querySelector('[data-venue-play-comment-notice]').hidden=!!response.can_post;
    }
    async function refreshRoster(pane){
        const token={};rosterLoads.set(pane,token);
        rosterBusy(pane,true);
        try{
            const url=new URL(pane.dataset.eventWallUrl,location.href);url.searchParams.set('include_roster','1');
            const response=await request(url);
            if(rosterLoads.get(pane)!==token)return;
            paintRoster(pane,response.roster,false,response.counts);
            paintWall(pane,response);
        }catch(e){
            if(rosterLoads.get(pane)!==token)return;
            rosterFailure(pane,e.message);
            pane.querySelector('[data-venue-play-wall-empty]').textContent=e.message;
        }finally{
            if(rosterLoads.get(pane)===token){rosterLoads.delete(pane);rosterBusy(pane,false);}
        }
    }
    function showList(){
        selectedId=null;list.hidden=false;detail.hidden=true;listFooter.hidden=false;detailFooter.hidden=true;
        title.textContent='Хочу здесь играть';
    }
    function showEvent(id){
        const pane=getPane(id);if(!pane)return;
        selectedId=Number(id);list.hidden=true;detail.hidden=false;listFooter.hidden=true;detailFooter.hidden=false;
        detailFooter.querySelectorAll('[data-venue-play-event-link]').forEach(el=>{el.hidden=Number(el.dataset.venuePlayEventLink)!==selectedId;});
        // Different event cards must open at the start of their own detail view.
        detail.scrollTop=0;
        detail.querySelectorAll('[data-venue-play-pane]').forEach(el=>{el.hidden=el!==pane;});
        title.textContent='Мероприятие';
        paintButtons(pane);
        if(savingPane!==pane)void refreshRoster(pane);
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
        saving=true;savingPane=pane;rosterLoads.delete(pane);rosterBusy(pane,true);notice(pane,'');
        let saved=false;
        try{
            const result=await request(pane.dataset.eventParticipationUrl,'PATCH',{status,include_roster:true});
            saved=true;
            pendingResponse=null;clearResume();
            pane.dataset.eventStatus=result.status;paintButtons(pane);
            paintRoster(pane,result.roster,true,result.counts);
            toast({
                confirmed:pane.dataset.eventOrganizer==='1'?'Ваше участие подтверждено.':'Вы успешно добавлены в участники. Следите за дальнейшими сообщениями организатора на портале.',
                tentative:'Ответ «Подумаю» сохранён. Вы можете изменить решение в любой момент до завершения мероприятия.',
                left:'Вы отказались от участия в мероприятии.',
            }[result.status]||'Ответ сохранён.');
            void refreshWall(pane);
        }catch(e){
            if(!saved&&e.status===401){authenticated=false;guestResponse(status);}
            else{
                const message=saved?'Ответ сохранён, но не удалось обновить список. Повторите загрузку.':e.message;
                if(saved)rosterFailure(pane,message);
                toast(message,true);notice(pane,message,true);
            }
        }finally{saving=false;savingPane=null;rosterBusy(pane,false);}
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
        pane.querySelector('[data-venue-play-roster-retry]').addEventListener('click',()=>{if(!saving)void refreshRoster(pane);});
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
        const legacy=document.querySelector('[data-venue-booking-auth], [data-modal-target="auth-entry-classic"]');
        if(legacy&&!document.querySelector('[data-app-auth-root]'))legacy.click();
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
