import { subscribePrivate } from '../../../js/realtime.js';
import { renderRentalInvoiceCard } from '../../../js/rental-invoice-card.js';

const root = document.querySelector('[data-rental-chat-root]');

if (root) {
    const bookingMode = root.dataset.rentalContext === 'booking';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const viewport = root.querySelector('[data-rental-chat-scroll]');
    const history = root.querySelector('[data-rental-chat-history]');
    const form = root.querySelector('[data-rental-chat-compose]');
    const input = form?.querySelector('textarea[name="body"]');
    const send = root.querySelector('[data-rental-send]');
    const file = form?.querySelector('[data-rental-file]');
    const filename = root.querySelector('[data-rental-file-name]');
    const errorElement = root.querySelector('[data-rental-error]');
    const statusElement = root.querySelector('[data-rental-status-label]');
    const dialog = root.querySelector('[data-rental-review-dialog]');
    const primary = root.querySelector('[data-rental-primary]');
    const older = root.querySelector('[data-rental-load-older]');
    const rail = root.querySelector('[data-conversation-rail]');
    const railList = rail?.querySelector('[data-rail-list]');
    const labels = {
        accept:'Принять заявку',
        confirm:'Подтвердить бронирование',
        reject:'Отклонить заявку',
        cancel:'Отменить бронирование',
        offer:'Предложить бронь',
        accept_offer:'Принять предложение',
        decline_offer:'Отклонить предложение',
        withdraw:'Отозвать предзаявку'
    };
    let lastEventVersion = Math.max(0,...[...history.querySelectorAll('[data-rental-event-version]')]
        .map(node=>Number(node.dataset.rentalEventVersion)||0));
    let newest = Math.max(0,...[...history.querySelectorAll('[data-rental-message-id]')]
        .map(node=>Number(node.dataset.rentalMessageId)||0));
    let oldest = Math.min(Infinity,...[...history.querySelectorAll('[data-rental-message-id]')]
        .map(node=>Number(node.dataset.rentalMessageId)||Infinity));
    let conversationId = root.dataset.rentalConversationId || '';
    let version = 0;
    let allowed = {};
    let proposalId = null;
    let pendingCommand = null;
    let busy = false;
    let loading = false;
    let pendingRead = false;
    let hasOlder = !older.hidden;
    const activeId = rail?.dataset.railActive || '';

    const showError = text => {
        if (errorElement) {
            errorElement.textContent = text || '';
            errorElement.hidden = !text;
        }
    };
    const uuid = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        // Only used by authenticated modern browsers, with initial UUID as a fallback.
        return form?.querySelector('input[name="client_id"]')?.value || '';
    };
    const requestJson = async (url, options = {}) => {
        const response = await fetch(url,{
            credentials:'same-origin',headers:{Accept:'application/json',...options.headers},...options
        });
        if(response.status===403||response.status===404){
            window.location.reload();
            throw new Error('Доступ к переписке или действию изменился. Обновите страницу.');
        }
        if(!response.ok){
            const payload=await response.json().catch(()=>({}));
            throw new Error(payload.message || 'Не удалось выполнить действие.');
        }
        return response.json().catch(()=>({}));
    };
    const urlForMessages = (params={}) => {
        const url=new URL(root.dataset.rentalMessagesUrl,window.location.origin);
        Object.entries(params).forEach(([key,value])=>url.searchParams.set(key,String(value)));
        return url;
    };
    const makeBubble = message => {
        const system = message.type === 'system';
        const cls = system ? 'app-messages__bubble--system' :
            (message.is_own ? 'app-messages__bubble--outgoing':'app-messages__bubble--incoming');
        const article=document.createElement('article');
        article.className='app-messages__bubble '+cls;
        article.dataset.rentalMessageId=String(message.id);
        article.dataset.rentalMessageUuid=message.message_id;
        article.dataset.rentalAt=message.created_at;
        const head=document.createElement('div');
        head.className='app-messages__bubble-head';
        const name=document.createElement('strong');
        name.textContent=system?'Система MSKBA':(message.is_own?'Вы':(message.author||'Участник'));
        head.append(name);article.append(head);
        if(message.body){
            const body=document.createElement('p');
            body.className='app-messages__bubble-body';
            body.textContent=message.body;
            article.append(body);
        }
        if(message.invoice){
            const invoiceCard = renderRentalInvoiceCard(message.invoice);
            if(invoiceCard) article.append(invoiceCard);
        }
        if(message.attachment){
            const link=document.createElement('a');
            link.className='app-messages__action';
            const base=root.dataset.rentalAttachmentBase;
            const suffix=bookingMode?'/attachment':'';
            link.href=base+'/'+encodeURIComponent(message.message_id)+suffix;
            link.textContent='Скачать: '+message.attachment.name;
            article.append(link);
        }
        const time=document.createElement('time');
        time.className='app-messages__timestamp';
        time.dateTime=message.created_at;
        const date=new Date(message.created_at);
        time.textContent=Number.isNaN(date.getTime())?'':date.toLocaleString('ru-RU',
            {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
        article.append(time);
        return article;
    };
    const sortChronologically = () => {
        [...history.children]
            .sort((a,b)=>{
                const at=Date.parse(a.dataset.rentalAt||'')||0;
                const bt=Date.parse(b.dataset.rentalAt||'')||0;
                return at-bt;
            })
            .forEach(node=>history.append(node));
    };
    const addEvents = (entries) => {
        if(!Array.isArray(entries)||!entries.length)return false;
        const seen=new Set([...history.querySelectorAll('[data-rental-event-version]')]
            .map(x=>x.dataset.rentalEventVersion));
        let added=false;
        for(const entry of entries){
            if(!entry.version||seen.has(String(entry.version)))continue;
            const article=document.createElement('article');
            article.className='app-messages__bubble app-messages__bubble--system';
            article.dataset.rentalEventVersion=String(entry.version);
            article.dataset.rentalAt=entry.occurred_at;
            const head=document.createElement('div');
            head.className='app-messages__bubble-head';
            const strong=document.createElement('strong');
            strong.textContent='Бронирование: '+entry.label;
            head.append(strong);article.append(head);
            if(entry.reason){
                const body=document.createElement('p');
                body.className='app-messages__bubble-body';
                body.textContent=entry.reason;
                article.append(body);
            }
            const time=document.createElement('time');
            time.className='app-messages__timestamp';
            time.dateTime=entry.occurred_at;
            const date=new Date(entry.occurred_at);
            time.textContent=Number.isNaN(date.getTime())?'':date.toLocaleString('ru-RU',
                {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
            article.append(time);
            history.append(article);
            seen.add(String(entry.version));
            lastEventVersion=Math.max(lastEventVersion,Number(entry.version)||0);
            added=true;
        }
        if(added){sortChronologically();void acknowledge();}
        return added;
    };
    const refreshEvents=async()=>{
        const eventsUrl=root.dataset.rentalEventsUrl;
        if(!eventsUrl)return;
        try{
            let page=await requestJson(eventsUrl);
            if((page.meta?.last_page||0)>1){
                const url=new URL(eventsUrl,window.location.origin);
                url.searchParams.set('page',page.meta.last_page);
                page=await requestJson(url);
            }
            const nearEnd=viewport.scrollHeight-viewport.scrollTop-viewport.clientHeight<100;
            if(addEvents(page.data)&&nearEnd)viewport.scrollTop=viewport.scrollHeight;
        }catch{ /* Booking status remains visible in the header if the event feed is unavailable. */ }
    };
    const addMessages = (messages,prepend=false) => {
        if (!Array.isArray(messages)||!messages.length) return false;
        const present=new Set([...history.querySelectorAll('[data-rental-message-id]')]
            .map(node=>node.dataset.rentalMessageId));
        const fresh=messages.filter(item=>!present.has(String(item.id)));
        if(!fresh.length)return false;
        const nearEnd=viewport.scrollHeight-viewport.scrollTop-viewport.clientHeight<110;
        const previousHeight=viewport.scrollHeight;
        if(prepend){
            [...fresh].reverse().forEach(message=>history.prepend(makeBubble(message)));
        }else{
            fresh.forEach(message=>history.append(makeBubble(message)));
        }
        sortChronologically();
        newest=Math.max(newest,...fresh.map(x=>Number(x.id)));
        oldest=Math.min(oldest,...fresh.map(x=>Number(x.id)));
        if(prepend){
            viewport.scrollTop+=viewport.scrollHeight-previousHeight;
        }else if(nearEnd){
            viewport.scrollTop=viewport.scrollHeight;
        }
        return true;
    };
    const attachConversation = id => {
        if(!id||id===conversationId)return;
        conversationId=id;
        root.dataset.rentalConversationId=id;
        subscribePrivate('venue-booking-conversations.'+id,'.booking.message.sent',()=>{void refresh()});
        subscribePrivate('venue-booking-conversations.'+id,'.interest.message.sent',()=>{void refresh()});
    };
    // The booking event also reports the first message before a conversation exists.
    if(bookingMode&&root.dataset.rentalBookingId){
        subscribePrivate('venue-bookings.'+root.dataset.rentalBookingId,'.booking.message.sent',()=>{void refresh()});
        subscribePrivate('venue-bookings.'+root.dataset.rentalBookingId,'.booking.updated',()=>{void refresh()});
    }
    if(conversationId){
        subscribePrivate('venue-booking-conversations.'+conversationId,'.booking.message.sent',()=>{void refresh()});
        // Pre-request messages use the existing interest broadcast on this same private channel.
        subscribePrivate('venue-booking-conversations.'+conversationId,'.interest.message.sent',()=>{void refresh()});
    }

    const refreshRail = async () => {
        if(!railList)return;
        try{
            const url=new URL(rail.dataset.railUrl,window.location.origin);
            url.searchParams.set('active',activeId);
            const response=await requestJson(url);
            if(response.html && response.html!==railList.innerHTML){
                const expanded=new Map([...railList.querySelectorAll('[data-conversation-group]')]
                    .map(x=>[x.dataset.conversationGroup,x.open]));
                railList.innerHTML=response.html;
                railList.querySelectorAll('[data-conversation-group]').forEach(x=>{
                    if(expanded.has(x.dataset.conversationGroup))x.open=expanded.get(x.dataset.conversationGroup);
                });
            }
        }catch{ /* The rail is a secondary presentation, not a message transport. */ }
    };
    const acknowledge = async () => {
        if(document.hidden||!document.hasFocus()||pendingRead)return;
        pendingRead=true;
        try{
            const result=await requestJson(root.dataset.rentalReadUrl,{
                method:'PATCH',headers:{'X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'}
            });
            document.dispatchEvent(new CustomEvent('notification:count-changed',{
                detail:{unread_count:result.unread_count}
            }));
            void refreshRail();
        }catch{ /* Retry when the tab is visible again. */ }
        finally{pendingRead=false;}
    };
    const reflectActions = data => {
        if(data.status_label&&statusElement)statusElement.textContent=data.status_label;
        const next=data.actions||{};
        allowed=Object.fromEntries(Object.entries(next).map(([key,value])=>
            [key,Boolean(value&&typeof value==='object'?value.allowed:value)]));
        if(data.version!==undefined)version=Number(data.version)||0;
        if(data.proposal_id!==undefined)proposalId=data.proposal_id;
        if(data.can_write!==undefined){
            if(input){input.disabled=!data.can_write;input.hidden=!data.can_write;}
            if(send)send.hidden=!data.can_write;
            if(file)file.closest('.app-messages__rental-attach').hidden=!data.can_write;
            const closed=root.querySelector('[data-rental-closed]');
            if(closed)closed.hidden=Boolean(data.can_write);
        }
        root.querySelectorAll('[data-rental-action]').forEach(button=>{
            button.hidden=!allowed[button.dataset.rentalAction];
        });
        const preferred=bookingMode?['accept','confirm']:['accept_offer','offer'];
        const selected=preferred.find(name=>allowed[name]);
        if(primary){
            primary.hidden=!selected;
            primary.dataset.rentalPrimaryAction=selected||'';
            primary.textContent=selected?labels[selected]:'';
        }
        if(data.linked_booking_url){
            const link=root.querySelector('[data-rental-booking-link]');
            if(link){link.href=data.linked_booking_url;link.hidden=false;}
        }
    };
    const refresh=async()=>{
        if(loading)return;
        loading=true;
        try{
            let messages, projection;
            if(bookingMode){
                const [msg,state]=await Promise.all([
                    requestJson(urlForMessages({after_id:newest})),
                    requestJson(root.dataset.rentalStatusUrl)
                ]);
                messages=msg;
                projection=state;
                reflectActions({
                    ...state,
                    status_label:state.status_label,
                    actions:state.actions
                });
            }else{
                messages=await requestJson(urlForMessages({after_id:newest}));
                reflectActions(messages);
            }
            attachConversation(messages.conversation_id);
            const added=addMessages(messages.messages);
            if(added)void acknowledge();
            if(root.dataset.rentalEventsUrl)void refreshEvents();
            if(bookingMode&&projection?.version){
                // The authoritative projection dictates the action set at every refresh.
                version=Number(projection.version);
            }
            showError('');
        }catch(e){
            showError(e.message||'Не удалось синхронизировать переписку.');
        }finally{loading=false;}
    };
    const loadOlder=async()=>{
        if(!hasOlder||!Number.isFinite(oldest)||loading)return;
        try{
            const data=await requestJson(urlForMessages({before_id:oldest}));
            addMessages(data.messages,true);
            hasOlder=Boolean(data.has_more);
            if(older)older.hidden=!hasOlder;
        }catch(e){showError(e.message);}
    };
    older?.addEventListener('click',()=>{void loadOlder()});
    viewport?.addEventListener('scroll',()=>{
        if(viewport.scrollTop<32&&hasOlder)void loadOlder();
    });
    file?.addEventListener('change',()=>{
        if(filename)filename.textContent=file.files?.[0]?.name||'';
    });
    form?.addEventListener('submit',async event=>{
        event.preventDefault();
        if(busy||input.disabled||(!input.value.trim()&&!file?.files?.length))return;
        busy=true;send.disabled=true;showError('');
        try{
            const payload=new FormData(form);
            payload.set('client_id',uuid());
            const url=bookingMode && file?.files?.length
                ? root.dataset.rentalAttachUrl:form.action;
            const response=await fetch(url,{
                method:'POST',credentials:'same-origin',
                headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
                body:payload
            });
            if(!response.ok){
                const data=await response.json().catch(()=>({}));
                throw new Error(data.message||'Не удалось отправить сообщение.');
            }
            input.value='';if(file)file.value='';if(filename)filename.textContent='';
            await refresh();
        }catch(e){showError(e.message);}
        finally{busy=false;send.disabled=Boolean(input.disabled);}
    });
    input?.addEventListener('keydown',event=>{
        if(event.key==='Enter'&&(event.metaKey||event.ctrlKey)&&!event.isComposing){
            event.preventDefault();form.requestSubmit();
        }
    });

    const runCommand=async(action,reason='')=>{
        if(busy||!allowed[action])return;
        busy=true;showError('');
        try{
            let url,fields;
            if(bookingMode){
                url='/account/venue-bookings/'+encodeURIComponent(root.dataset.rentalBookingId)+'/'+action;
                fields={idempotency_key:uuid(),version,reason};
            }else if(action==='offer'){
                url=root.dataset.rentalPreOfferUrl;fields={};
            }else if(action==='withdraw'){
                url=root.dataset.rentalPreWithdrawUrl;fields={};
            }else{
                url=root.dataset.rentalPreRespondUrl;
                fields={booking_id:proposalId,decision:action==='accept_offer'?'accept':'decline'};
            }
            const data=new FormData();
            Object.entries(fields).forEach(([key,value])=>{
                if(value!==null&&value!==undefined)data.append(key,String(value));
            });
            const response=await fetch(url,{method:'POST',credentials:'same-origin',
                headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
                body:data
            });
            if(!response.ok){
                const data=await response.json().catch(()=>({}));
                throw new Error(data.message||'Действие сейчас недоступно.');
            }
            dialog?.close();
            await refresh();
            await refreshRail();
        }catch(e){showError(e.message);}
        finally{busy=false;}
    };
    const openDialog=action=>{
        if(!allowed[action]||!dialog)return;
        const needsReason=action==='reject'||action==='cancel';
        const reason=dialog.querySelector('[data-rental-reason]');
        const label=dialog.querySelector('[data-rental-reason-label]');
        reason.hidden=!needsReason;label.hidden=!needsReason;
        reason.value='';
        dialog.querySelector('[data-rental-dialog-title]').textContent=labels[action]+'?';
        dialog.dataset.rentalDecision=action;
        root.querySelector('[data-chat-actions]')?.removeAttribute('open');
        dialog.showModal();
    };
    root.addEventListener('click',event=>{
        const btn=event.target.closest('[data-rental-action],[data-rental-primary]');
        if(btn)openDialog(btn.dataset.rentalAction||btn.dataset.rentalPrimaryAction);
        if(event.target.closest('[data-rental-dialog-cancel]'))dialog?.close();
        if(event.target.closest('[data-rental-dialog-confirm]')){
            const action=dialog?.dataset.rentalDecision;
            if(action)void runCommand(action,dialog.querySelector('[data-rental-reason]').value.trim());
        }
        if(event.target===dialog)dialog.close();
    });

    const header=document.querySelector('[data-app-header][data-notification-user-id]');
    if(header){
        subscribePrivate('users.'+header.dataset.notificationUserId,'.notification.created',()=>{
            void refresh();void refreshRail();
        });
    }
    viewport.scrollTop=viewport.scrollHeight;
    void refresh();
    void acknowledge();
    document.addEventListener('visibilitychange',()=>{
        if(!document.hidden){void refresh();void acknowledge();}
    });
    window.addEventListener('focus',()=>{void refresh();void acknowledge()});
    window.addEventListener('mskba:realtime-state',event=>{
        if(event.detail?.state==='connected'){void refresh();void acknowledge();}
    });
    const poll=window.setInterval(()=>{
        if(!document.hidden){void refresh();}
    },15000);
    window.addEventListener('pagehide',()=>window.clearInterval(poll),{once:true});
}
