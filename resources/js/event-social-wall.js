// One controller for event discussion on pages, in dialogs and in legacy event views.
import {bindChatActions,setChatComposerAccess,syncChatActions} from './chat-composer-actions.js';

const deleteControllers=new WeakMap();

export function bindBackdropDismiss(modal,canClose=()=>true){
    let outsidePointer=null;
    modal.addEventListener('pointerdown',event=>{
        outsidePointer=event.target===modal?event.pointerId:null;
    });
    modal.addEventListener('pointerup',event=>{
        if(outsidePointer===event.pointerId&&event.target===modal&&canClose())modal.close();
        outsidePointer=null;
    });
    modal.addEventListener('pointercancel',()=>{outsidePointer=null;});
}

function deletionConfirmation(dialog){
    if(deleteControllers.has(dialog))return deleteControllers.get(dialog);
    const confirm=dialog.querySelector('[data-venue-play-delete-confirm]');
    const close=dialog.querySelector('[data-venue-play-delete-close]');
    const error=dialog.querySelector('[data-venue-play-delete-error]');
    let pending=null, busy=false;
    close.addEventListener('click',()=>{if(!busy)dialog.close();});
    dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    dialog.addEventListener('close',()=>{pending=null;error.hidden=true;error.textContent='';});
    confirm.addEventListener('click',async()=>{
        if(!pending||busy)return;
        busy=true;confirm.disabled=true;close.disabled=true;error.hidden=true;
        try{await pending();dialog.close();}
        catch(e){error.textContent=e.message;error.hidden=false;}
        finally{busy=false;confirm.disabled=false;close.disabled=false;}
    });
    bindBackdropDismiss(dialog,()=>!busy);
    const open=action=>{
        if(busy||dialog.open)return;
        pending=action;error.hidden=true;dialog.showModal();
    };
    deleteControllers.set(dialog,open);
    return open;
}

const controllers=new WeakMap();
export function mountEventDiscussion(root, {
    deleteDialog,
    authenticated:initialAuthentication=false,
    guestResponse=()=>{},
    toast=()=>{},
    onRosterUpdate=()=>{},
    onParticipationSaved=()=>{},
}){
    if(controllers.has(root))return controllers.get(root);
    const panes=root.matches('[data-venue-play-pane]')?[root]:[...root.querySelectorAll('[data-venue-play-pane]')];
    let authenticated=initialAuthentication, saving=false, savingPane=null;
    const rosterLoads=new WeakMap(), wallPages=new WeakMap();
    const messages=pane=>pane.querySelector('[data-venue-play-messages]');
    const scrollBottom=pane=>{const list=messages(pane);list.scrollTop=list.scrollHeight;};
    const jumpToMessages=pane=>{
        const wall=pane.querySelector('.venue-play-dialog__wall');
        wall.scrollIntoView({block:'end'});
        scrollBottom(pane);
    };
    const entryIds=list=>[...list.querySelectorAll('[data-entry-id]')].map(node=>Number(node.dataset.entryId));
    const regroup=list=>{
        const items=[...list.querySelectorAll('[data-entry-id]')].sort((a,b)=>
            (a.dataset.messageStamp||'').localeCompare(b.dataset.messageStamp||'')||Number(a.dataset.entryId)-Number(b.dataset.entryId));
        const fragment=document.createDocumentFragment();
        let previousDay='';
        for(const item of items){
            const day=(item.dataset.messageStamp||'').slice(0,10);
            if(day&&day!==previousDay){
                const separator=document.createElement('li');separator.className='venue-play-dialog__date';
                const label=document.createElement('time');label.dateTime=day;
                label.textContent=new Intl.DateTimeFormat('ru-RU',{day:'numeric',month:'long',timeZone:'UTC'}).format(new Date(day+'T00:00:00Z'));
                separator.append(label);fragment.append(separator);previousDay=day;
            }
            fragment.append(item);
        }
        list.querySelectorAll('.venue-play-dialog__date').forEach(node=>node.remove());
        list.append(fragment);
    };
    const confirmDelete=deletionConfirmation(deleteDialog);
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
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
        if(!response.ok){const message=response.status>=500?'Не удалось выполнить действие. Попробуйте ещё раз.':(Object.values(payload.errors||{}).flat()[0]||payload.message||'Не удалось выполнить действие.');const error=new Error(message);error.status=response.status;throw error;}
        return payload;
    }
    const paintButtons=pane=>{
        const status=pane.dataset.eventStatus||'';
        const choices=[...pane.querySelectorAll('[data-venue-play-rsvp]')];
        if(!choices.length)return;
        const attendance=pane.querySelector('[data-venue-play-attendance]');
        if(!attendance)return;
        const confirmed=status==='confirmed';
        attendance.dataset.venuePlayRsvp=confirmed?'left':'confirmed';
        attendance.textContent=confirmed?'Не пойду':'Пойду';
        choices.forEach(button=>{
            const own=button!==attendance&&button.dataset.venuePlayRsvp===status;
            button.classList.toggle('is-selected',own);
            if(root.classList.contains('venue-play-dialog--app')){
                button.classList.toggle('primary',own);button.classList.toggle('secondary',!own);
            }else{
                button.classList.toggle('btn--primary',own);button.classList.toggle('btn--secondary',!own);
            }
            if(button===attendance)button.removeAttribute('aria-pressed');
            else button.setAttribute('aria-pressed',String(own));
        });
    };
    const icon=(id)=>{
        if(!document.getElementById(id)){const fallback=document.createElement('i');fallback.className='ti ti-'+(id==='star'?'star-filled':'user');fallback.setAttribute('aria-hidden','true');return fallback;}
        const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');
        const use=document.createElementNS('http://www.w3.org/2000/svg','use');use.setAttribute('href','#'+id);svg.append(use);return svg;
    };
    const rosterBusy=(pane,busy)=>{
        pane.querySelector('[data-venue-play-roster-surface]')?.setAttribute('aria-busy',String(busy));
        const loader=pane.querySelector('[data-venue-play-roster-loading]');
        if(loader)loader.hidden=!busy;
        pane.querySelectorAll('[data-venue-play-rsvp]').forEach(button=>button.disabled=busy);
    };
    function paintRoster(pane,roster,scrollToOwn=false,counts=null){
        if(!roster||!Array.isArray(roster.members))throw Error('Не удалось получить актуальный список участников.');
        const list=pane.querySelector('[data-venue-play-participants]');
        if(!list){
            pane.dataset.eventStatus=roster.viewer_status||'';
            pane.dataset.eventOrganizer=roster.viewer_is_organizer?'1':'0';
            paintButtons(pane);
            onRosterUpdate(pane,roster,counts);
            return;
        }
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
            // Only use the already privacy-filtered public nickname, never an internal identity.
            if(member.url&&member.name&&member.name!=='Участник')row.dataset.chatMention=full;
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
        onRosterUpdate(pane,roster,counts);
        list.scrollTop=previousScroll;
        if(scrollToOwn&&ownRow){
            const rowRect=ownRow.getBoundingClientRect(),listRect=list.getBoundingClientRect();
            if(rowRect.top<listRect.top)list.scrollTop+=rowRect.top-listRect.top;
            else if(rowRect.bottom>listRect.bottom)list.scrollTop+=rowRect.bottom-listRect.bottom;
        }
    }
    const rosterFailure=(pane,message)=>{
        const empty=pane.querySelector('[data-venue-play-roster-empty]');
        if(!empty){notice(pane,message,true);return;}
        if(!pane.querySelector('[data-venue-play-participants]').children.length){empty.hidden=false;empty.textContent=message;}
        pane.querySelector('[data-venue-play-roster-retry]').hidden=false;
    };
    const appendEntries=(pane,entries,prepend=false)=>{
        const list=pane.querySelector('[data-venue-play-messages]');
        const older=document.createDocumentFragment();
        for(const entry of entries){
            const existing=list.querySelector('[data-entry-id="'+Number(entry.id)+'"]');
            if(entry.deleted){existing?.remove();continue;}
            const item=document.createElement('li');item.dataset.entryId=String(entry.id);
            item.classList.toggle('is-system',!!entry.system);
            if(entry.kind==='event_rsvp_changed'&&['confirmed','tentative','left'].includes(entry.rsvp_status))item.dataset.rsvpStatus=entry.rsvp_status;
            if(['like','dislike'].includes(entry.reaction))item.dataset.reaction=entry.reaction;
            item.classList.toggle('is-deleted',!!entry.deleted);
            const label=document.createElement('strong');label.textContent=entry.system?'Событие':(entry.actor||'Участник');
            const time=document.createElement('time');
            const stamp=/^([0-9]{2})\.([0-9]{2})\.([0-9]{4}) ([0-9]{2}:[0-9]{2})$/.exec(entry.date||'');
            if(stamp){item.dataset.messageStamp=stamp[3]+'-'+stamp[2]+'-'+stamp[1]+'T'+stamp[4];time.dateTime=item.dataset.messageStamp;time.title=entry.date;}
            time.textContent=(stamp?stamp[4]:(entry.date||''))+(entry.edited?' (изменено)':'');
            const body=document.createElement('p');body.textContent=entry.text||'';
            if(typeof entry.comment_body==='string')item.dataset.commentBody=entry.comment_body;
            item.append(label,time,body);
            if(entry.can_edit&&entry.edit_url){
                const edit=document.createElement('button');edit.type='button';edit.className='venue-play-dialog__edit';
                edit.dataset.venuePlayEdit=entry.edit_url;edit.dataset.version=String(entry.version);edit.dataset.deleteUrl=entry.delete_url||'';
                edit.setAttribute('aria-label','Редактировать сообщение');edit.title='Редактировать сообщение';
                edit.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5 -10.5a2.828 2.828 0 0 0 -4 -4l-10.5 10.5v4"/><path d="M13.5 6.5l4 4"/></svg>';item.append(edit);
            }
            if(existing){existing.replaceWith(item);}
            else if(prepend){older.append(item);}
            else list.append(item);
        }
        if(prepend)list.prepend(older);
        regroup(list);
        const empty=pane.querySelector('[data-venue-play-wall-empty]');
        empty.hidden=list.childElementCount>0;
        if(!empty.hidden)empty.textContent=pane.hasAttribute('data-event-report-wall')?'Комментариев пока нет':'Сообщений пока нет.';
    };
    function paintWallAccess(pane,response){
        const form=pane.querySelector('[data-venue-play-message-form]');
        setChatComposerAccess(form,response.can_post);
        const noticeNode=pane.querySelector('[data-venue-play-comment-notice]');
        if(response.can_post_reason){noticeNode.textContent=response.can_post_reason;}
        else if(!response.can_post && !pane.hasAttribute('data-event-report-wall')){
            noticeNode.replaceChildren(document.createTextNode('Написать сообщение могут участники мероприятия. '));
            const href=pane.dataset.eventPageUrl;
            if(href){const a=document.createElement('a');a.className='venue-play-dialog__participation-link';a.href=href;a.textContent='Участвовать';noticeNode.append(a);}
        }
        noticeNode.hidden=!!response.can_post;
        const noticeDivider=pane.querySelector('[data-report-notice-divider]');
        if(noticeDivider)noticeDivider.hidden=noticeNode.hidden;
        if(!response.can_post)pane.querySelectorAll('[data-venue-play-edit]').forEach(b=>b.hidden=true);
        if(pane.hasAttribute('data-event-report-wall')){
            pane.dataset.reportReaction=response.reaction||'';
            pane.dataset.canReact=response.can_react?'1':'0';
            pane.querySelectorAll('[data-report-reaction]').forEach(b=>{
                b.disabled=!response.can_react;
                b.setAttribute('aria-pressed',String(b.dataset.reportReaction===response.reaction));
                const count=response[b.dataset.reportReaction==='like'?'likes':'dislikes'];
                const counter=b.querySelector('[data-report-reaction-count]');
                if(counter&&Number.isInteger(count)){counter.textContent=String(count);b.setAttribute('aria-label',(b.dataset.reportReaction==='like'?'Нравится: ':'Не нравится: ')+count);}
            });
            const tally=document.querySelector('[data-report-like-count]');
            if(tally&&Number.isInteger(response.likes)){tally.textContent=String(response.likes);tally.setAttribute('aria-label','Лайков: '+response.likes);}
        }
    }
    function paintWall(pane,response){
        const list=messages(pane);
        wallPages.set(pane,{hasOlder:!!response.has_older,loading:false});
        list.replaceChildren();
        appendEntries(pane,response.entries||[]);
        paintWallAccess(pane,response);
        scrollBottom(pane);
    }
    async function loadOlder(pane){
        const list=messages(pane),page=wallPages.get(pane);
        const ids=entryIds(list),first=ids.length?Math.min(...ids):null;
        if(!page?.hasOlder||page.loading||!first||list.scrollTop>24||list.clientHeight===0)return;
        page.loading=true;
        try{
            const url=new URL(pane.dataset.eventWallUrl,location.href);
            url.searchParams.set('before_id',first);
            const response=await request(url);
            if(wallPages.get(pane)!==page)return;
            const height=list.scrollHeight,top=list.scrollTop;
            appendEntries(pane,response.entries||[],true);
            page.hasOlder=!!response.has_older;
            list.scrollTop=top+list.scrollHeight-height;
        }catch(e){if(wallPages.get(pane)===page)notice(pane,e.message,true);}
        finally{page.loading=false;}
    }
    async function refreshWall(pane,posted=null,reset=false,follow=true){
        const list=messages(pane),page=wallPages.get(pane);
        const atBottom=list.scrollHeight-list.clientHeight-list.scrollTop<=24;
        try{
            if(reset||!page||!list.lastElementChild){
                const response=await request(pane.dataset.eventWallUrl);
                if(wallPages.get(pane)!==page)return;
                paintWall(pane,response);
                return;
            }
            // Merge new entries instead of throwing away history already loaded.
            let cursor=Math.max(...entryIds(list));
            while(true){
                const url=new URL(pane.dataset.eventWallUrl,location.href);
                url.searchParams.set('after_id',String(cursor));
                const response=await request(url);
                if(wallPages.get(pane)!==page)return;
                const entries=response.entries||[];
                appendEntries(pane,entries);
                paintWallAccess(pane,response);
                if(entries.length<50)break;
                cursor=Math.max(cursor,...entries.map(entry=>Number(entry.id)));
            }
            if(posted&&!list.querySelector('[data-entry-id="'+Number(posted.id)+'"]'))appendEntries(pane,[posted]);
            if(follow||atBottom)scrollBottom(pane);
        }catch(e){
            if(wallPages.get(pane)!==page)return;
            if(posted){
                if(!list.querySelector('[data-entry-id="'+Number(posted.id)+'"]'))appendEntries(pane,[posted]);
                scrollBottom(pane);
            }else if(follow)notice(pane,e.message,true);
        }
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
    async function respond(pane,status){
        if(saving)return;
        if(!authenticated){guestResponse(status);return;}
        saving=true;savingPane=pane;rosterLoads.delete(pane);rosterBusy(pane,true);notice(pane,'');
        let saved=false;
        try{
            const result=await request(pane.dataset.eventParticipationUrl,'PATCH',{status,include_roster:true});
            saved=true;
            onParticipationSaved(pane,result);
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
    root.querySelectorAll('[data-venue-play-rsvp]').forEach(button=>button.addEventListener('click',()=>{
        const pane=button.closest('[data-venue-play-pane]');
        const status=button.dataset.venuePlayRsvp;
        if(pane&&['confirmed','tentative','left'].includes(status))void respond(pane,status);
    }));
    for(const pane of panes){
        let reacting=false, pendingReaction=null, reactionRequestId=null;
        const reactionDialog=pane.querySelector('[data-report-reaction-dialog]');
        const reactionForm=reactionDialog?.querySelector('[data-report-reaction-form]');
        const reactionField=reactionForm?.querySelector('[name="body"]');
        const reactionError=reactionDialog?.querySelector('[data-report-reaction-error]');
        const canComment=()=>pane.querySelector('[data-chat-composer]')?.dataset.chatCanPost==='true';
        async function saveReaction(reaction,body='',requestId=null){
            if(reacting||pane.dataset.canReact!=='1')return;
            reacting=true;
            pane.querySelectorAll('[data-report-reaction]').forEach(b=>b.disabled=true);
            reactionForm?.querySelectorAll('button,textarea').forEach(b=>b.disabled=true);
            if(reactionError)reactionError.hidden=true;
            try{
                const result=await request(pane.dataset.reactionUrl,'PUT',{reaction,...(body?{body,client_request_id:requestId}:{})});
                paintWallAccess(pane,{...result,can_post:canComment(),can_post_reason:pane.querySelector('[data-venue-play-comment-notice]').textContent});
                if(reactionDialog?.open)reactionDialog.close();
                await refreshWall(pane,result.entry||null);
                if(result.entry){
                    const list=messages(pane);
                    if(!list.querySelector('[data-entry-id="'+Number(result.entry.id)+'"]'))appendEntries(pane,[result.entry]);
                    list.querySelector('[data-entry-id="'+Number(result.entry.id)+'"]')?.scrollIntoView({block:'nearest',behavior:'smooth'});
                }
            }catch(e){
                if(reactionDialog?.open){reactionError.textContent=e.message;reactionError.hidden=false;}
                else notice(pane,e.message,true);
            }finally{
                reacting=false;
                reactionForm?.querySelectorAll('button,textarea').forEach(b=>b.disabled=false);
                pane.querySelectorAll('[data-report-reaction]').forEach(b=>{b.disabled=pane.dataset.canReact!=='1';});
            }
        }
        if(reactionDialog){
            bindBackdropDismiss(reactionDialog,()=>!reacting);
            reactionDialog.addEventListener('cancel',event=>{if(reacting)event.preventDefault();});
            reactionDialog.querySelectorAll('[data-report-reaction-cancel]').forEach(button=>button.addEventListener('click',()=>{if(!reacting)reactionDialog.close();}));
            reactionForm.addEventListener('submit',event=>{
                event.preventDefault();
                if(!pendingReaction||reacting)return;
                const body=reactionField.value.trim();
                if([...body].length>200)return;
                void saveReaction(pendingReaction,body,reactionRequestId);
            });
            reactionField.addEventListener('input',()=>{reactionRequestId=crypto.randomUUID();});
        }
        pane.querySelectorAll('[data-report-reaction]').forEach(button=>button.addEventListener('click',()=>{
            if(reacting||pane.dataset.canReact!=='1'||reactionDialog?.open)return;
            const reaction=pane.dataset.reportReaction===button.dataset.reportReaction?null:button.dataset.reportReaction;
            if(reaction&&reactionDialog&&canComment()){
                pendingReaction=reaction;reactionRequestId=crypto.randomUUID();reactionField.value='';reactionError.hidden=true;
                reactionDialog.querySelector('[data-report-reaction-title]').textContent=reaction==='like'?'Нравится':'Не нравится';
                reactionDialog.showModal();
            }else void saveReaction(reaction);
        }));
        pane.querySelector('[data-venue-play-roster-retry]')?.addEventListener('click',()=>{if(!saving)void refreshRoster(pane);});
        const messageList=messages(pane);
        messageList.addEventListener('scroll',()=>{void loadOlder(pane);},{passive:true});
        const form=pane.querySelector('[data-venue-play-message-form]');
        bindChatActions(form);
        const field=form.querySelector('[name="body"]');
        const cancel=form.querySelector('[data-venue-play-edit-cancel]');
        const remove=form.querySelector('[data-venue-play-delete]');
        const submit=form.querySelector('[type="submit"]');
        let editing=null, draft='', sending=false;
        const count=()=>{pane.querySelector('[data-venue-play-chars]').textContent=String(Math.max(0,200-[...field.value].length));};
        const finishEdit=()=>{
            field.value=draft;draft='';editing=null;form.classList.remove('is-editing');cancel.hidden=true;remove.hidden=true;syncChatActions(form);
            submit.title='Отправить сообщение';submit.setAttribute('aria-label',submit.title);count();
        };
        field.addEventListener('input',count);
        const mention=name=>{
            if(!name||form.dataset.chatCanPost!=='true'||sending||field.disabled)return;
            const prefix='@'+name+' ';
            if(!field.value.startsWith(prefix)&&field.value.length+prefix.length<=field.maxLength)field.setRangeText(prefix,0,0,'end');
            count();field.focus({preventScroll:true});field.setSelectionRange(field.value.length,field.value.length);jumpToMessages(pane);
        };
        pane.addEventListener('mskba:event:mention',event=>mention(event.detail?.name));
        pane.querySelector('[data-venue-play-participants]')?.addEventListener('click',event=>{
            const row=event.target.closest('[data-chat-mention]');
            if(!row||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
            if(form.dataset.chatCanPost!=='true'){
                const reportPane=document.querySelector('[data-event-report-wall]');
                if(reportPane?.querySelector('[data-chat-composer]')?.dataset.chatCanPost==='true'){
                    event.preventDefault();reportPane.dispatchEvent(new CustomEvent('mskba:event:mention',{detail:{name:row.dataset.chatMention}}));
                }
                return;
            }
            if(sending||field.disabled)return;
            event.preventDefault();
            const prefix='@'+row.dataset.chatMention+' ';
            if(!field.value.startsWith(prefix)&&field.value.length+prefix.length<=field.maxLength){
                field.setRangeText(prefix,0,0,'end');
            }
            count();
            field.focus({preventScroll:true});
            field.setSelectionRange(field.value.length,field.value.length);
            jumpToMessages(pane);
        });
        remove.addEventListener('click',()=>{
            if(!editing?.deleteUrl||sending)return;
            const selected=editing;
            confirmDelete(async()=>{
                sending=true;submit.disabled=true;field.disabled=true;cancel.disabled=true;remove.disabled=true;
                try{
                    const result=await request(selected.deleteUrl,'DELETE');
                    appendEntries(pane,[result.entry]);finishEdit();notice(pane,'');
                }finally{sending=false;submit.disabled=false;field.disabled=false;cancel.disabled=false;remove.disabled=false;}
            });
        });
        form.addEventListener('mskba:chat-action',event=>{
            if(event.detail.id==='cancel-edit'&&!sending){finishEdit();field.focus();}
        });
        pane.querySelector('[data-venue-play-messages]').addEventListener('click',event=>{
            const edit=event.target.closest('[data-venue-play-edit]');
            if(!edit||sending||form.dataset.chatCanPost!=='true')return;
            if(!editing)draft=field.value;
            const row=edit.closest('li');
            const original=row.dataset.commentBody??row.querySelector('p').textContent;
            editing={url:edit.dataset.venuePlayEdit,deleteUrl:edit.dataset.deleteUrl,version:Number(edit.dataset.version),original};
            field.value=original;form.classList.add('is-editing');cancel.hidden=false;remove.hidden=!editing.deleteUrl;syncChatActions(form);
            submit.title='Сохранить изменения';submit.setAttribute('aria-label',submit.title);
            count();field.focus();field.setSelectionRange(field.value.length,field.value.length);
        });
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            if(!authenticated){guestResponse(null);return;}
            if(form.dataset.chatCanPost!=='true')return;
            const body=field.value.trim();if(!body||[...body].length>200)return;
            if(sending)return;
            if(editing&&body===editing.original.trim()){finishEdit();return;}
            sending=true;submit.disabled=true;field.disabled=true;cancel.disabled=true;remove.disabled=true;
            try{
                if(editing){
                    const response=await request(editing.url,'PATCH',{body,version:editing.version});
                    appendEntries(pane,[response.entry]);finishEdit();scrollBottom(pane);notice(pane,'');
                }else{
                    const response=await request(pane.dataset.eventPostUrl,'POST',{body,client_request_id:crypto.randomUUID()});
                    field.value='';count();
                    await refreshWall(pane,response.entry);notice(pane,'Сообщение отправлено.');
                }
            }catch(e){if(e.status===409)await refreshWall(pane,null,true);notice(pane,e.message,true);}
            finally{sending=false;submit.disabled=false;field.disabled=false;cancel.disabled=false;remove.disabled=false;}
        });
    }

    const controller={
        paintButtons, refreshRoster, refreshWall, respond, jumpToMessages,
        get savingPane(){return savingPane;},
        get authenticated(){return authenticated;},
        setAuthenticated(value){authenticated=!!value;},
    };
    controllers.set(root,controller);
    return controller;
}
