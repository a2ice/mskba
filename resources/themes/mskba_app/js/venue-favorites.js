/* MSKBA universal venue hearts: works for guest and authenticated web sessions.
 * Add data-favorite-venue="<id>" to any standalone button. Cards/previews
 * with venue URLs also get the same accessible heart, outside link elements.
 */
(() => {
    const endpoints = {
        statuses:'/favorites/venue-statuses',
        toggle:'/favorites/toggle',
        preferences:'/favorites/preferences',
    };
    const known = new Map();
    const pending = new Set();
    let frame = null;
    let dialog = null;
    let feedbackRegion = null;
    const svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#heart"/></svg>';
    const idFromLink = (href) => {
        if (!href) return null;
        try {
            const url = new URL(href,location.origin);
            if (url.origin !== location.origin) return null;
            const match = url.pathname.match(/^\/venues\/([0-9]+)(?:-[^/]*)?\/?$/);
            return match ? Number(match[1]) : null;
        } catch { return null; }
    };
    const request = async (url,method='GET',payload=null) => {
        const res = await fetch(url,{method,credentials:'same-origin',headers:{
            Accept:'application/json','Content-Type':'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'',
        },...(payload?{body:JSON.stringify(payload)}:{})});
        if(!res.ok)throw new Error(res.status===419?'Сессия истекла. Обновите страницу.':
            res.status===429?'Слишком много запросов. Попробуйте позже.':
            'Не удалось сохранить изменения. Попробуйте ещё раз.');
        return res.json();
    };
    const getName = (state) => state==='active'?'Убрать из избранного':state==='trashed'?'Восстановить из корзины':'Добавить в избранное';
    function paint(id) {
        const state = known.get(id)?.state || 'none';
        const count = known.get(id)?.count;
        if (count !== undefined) {
            document.querySelectorAll('[data-venue-favorite-count]').forEach(node => {
                if (Number(node.dataset.venueFavoriteCountId) === id) node.textContent = String(count);
            });
        }
        document.querySelectorAll('[data-favorite-venue]').forEach(btn=>{
            if(Number(btn.dataset.favoriteVenue)!==id) return;
            btn.classList.add('mskba-favorite-heart');
            if (!btn.querySelector('svg use[href="#heart"]')) btn.innerHTML = svg;
            btn.setAttribute('aria-pressed',String(state==='active'));
            btn.setAttribute('aria-label',getName(state));
            btn.title=state==='active'?'Добавлено в избранное':getName(state);
            btn.disabled=pending.has(id);
        });
    }
    const create = (id, placement='card') => {
        const b=document.createElement('button');
        b.type='button';b.dataset.favoriteVenue=String(id);
        b.className='mskba-favorite-heart mskba-favorite-heart--'+placement;
        b.setAttribute('aria-pressed','false');
        b.setAttribute('aria-label','Добавить в избранное');
        b.title='Добавить в избранное';
        b.innerHTML=svg;
        return b;
    };
    function enhance() {
        document.querySelectorAll('[data-favorite-venue]').forEach(btn=>{
            const id=Number(btn.dataset.favoriteVenue);
            if(Number.isInteger(id)&&id>0) {paint(id);if(!known.has(id))pending.add(id);}
        });
        // Shared catalog/list/home/modal previews: never place a button inside <a>.
        const selectors=[
            '.venue-catalog-item',
            '.home-venue-card',
            '.venue-selector-preview',
            '[data-entity-preview-content]',
            '.venue-card',
            '.app-venue-card',
        ];
        document.querySelectorAll(selectors.join(',')).forEach(card=>{
            const href=card.matches('a')?card.getAttribute('href'):
                card.querySelector('a[href*="/venues/"]')?.getAttribute('href');
            const id=Number(card.dataset.venueId)||idFromLink(href);
            if(!id||card.querySelector('[data-favorite-venue]') ||
                (card.matches('a') && card.parentElement?.classList.contains('mskba-favorite-card-wrap')))return;
            const button=create(id,'card');
            // A sibling outside the link for keyboard/click safety.
            if(card.matches('a')) {
                const wrapper=document.createElement('div');
                wrapper.className='mskba-favorite-card-wrap';
                card.parentElement?.insertBefore(wrapper,card);
                wrapper.appendChild(card);wrapper.appendChild(button);
            }else{
                card.classList.add('mskba-favorite-card-wrap');
                card.appendChild(button);
            }
            pending.add(id);
        });
        if(pending.size){void hydrate();}
    }
    let loading=false;
    async function hydrate(){
        if(loading)return;
        const ids=[...pending].filter(id=>!known.has(id)).slice(0,100);
        if(!ids.length){pending.clear();return;}
        loading=true;
        try {
            const url=new URL(endpoints.statuses,location.origin);
            ids.forEach(id=>url.searchParams.append('ids[]',String(id)));
            const data=await request(url);
            for(const id of ids){
                known.set(id,{...(data.data?.[id]||{state:'none',notify_upcoming_events:false}),
                    count:Number(data.counts?.[id]||0)});
                pending.delete(id);paint(id);
            }
        }catch{
            ids.forEach(id=>pending.delete(id));
            // Prefer leaving the original state unknown over pretending saved=false.
        }finally{
            loading=false;if(pending.size)setTimeout(()=>void hydrate(),0);
        }
    }
    function toast(message, failed = false) {
        if (!feedbackRegion) {
            feedbackRegion=document.createElement('div');
            feedbackRegion.className='mskba-action-toast-region';
            feedbackRegion.setAttribute('role','region');
            feedbackRegion.setAttribute('aria-label','Результаты действий');
            document.body.appendChild(feedbackRegion);
        }
        const item=document.createElement('div');
        item.className='mskba-action-toast'+(failed?' mskba-action-toast--error':'');
        item.setAttribute('role',failed?'alert':'status');
        const label=document.createElement('span');
        label.textContent=message;
        const close=document.createElement('button');
        close.type='button';close.textContent='×';close.setAttribute('aria-label','Закрыть уведомление');
        close.addEventListener('click',()=>item.remove());
        item.append(label,close);
        feedbackRegion.appendChild(item);
        window.setTimeout(()=>item.remove(),6000);
    }
    async function checkSubscription(id) {
        const url=new URL(endpoints.statuses,location.origin);
        url.searchParams.append('ids[]',String(id));
        const result=await request(url);
        return Boolean(result.data?.[String(id)]?.subscribed);
    }
    function successDialog(id) {
        if(!dialog) {
            dialog=document.createElement('dialog');
            dialog.className='mskba-favorites-confirm';
            dialog.setAttribute('aria-labelledby','mskba-favorites-confirm-title');
            dialog.innerHTML = '<div class="mskba-favorites-confirm__header"><h2 id="mskba-favorites-confirm-title">Площадка добавлена в избранное</h2><button type="button" class="mskba-favorites-confirm__close" aria-label="Закрыть">×</button></div>'+
                '<p>Ты сможешь найти площадку в разделе «Избранное» личного кабинета.</p>'+
                '<p class="mskba-favorites-confirm__checking" role="status">Проверяем подписку…</p>'+
                '<div class="mskba-favorites-confirm__subscription" hidden><p><strong>Ты уже подписан на эту площадку.</strong></p><p>Уведомления о новых играх, тренировках и турнирах уже включены.</p><a class="button primary mskba-favorites-confirm__link" href="/account/subscriptions?type=venue">Смотреть подписки</a></div>'+
                '<div class="mskba-favorites-confirm__offer" hidden><label class="mskba-favorites-confirm__option"><input type="checkbox" checked><span>Получать уведомления о новых играх, тренировках и турнирах на этой площадке</span></label><button type="button" class="button primary mskba-favorites-confirm__apply">Применить</button></div>'+
                '<p class="mskba-favorites-confirm__error error" role="alert" hidden></p>';
            document.body.appendChild(dialog);
            dialog.querySelector('.mskba-favorites-confirm__close').addEventListener('click',()=>dialog.close());
            dialog.addEventListener('cancel',()=>dialog.close());
            dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
            dialog.querySelector('.mskba-favorites-confirm__apply').addEventListener('click',async()=>{
                const button=dialog.querySelector('.mskba-favorites-confirm__apply');
                const error=dialog.querySelector('.mskba-favorites-confirm__error');
                const chosen=dialog.querySelector('input[type=checkbox]').checked;
                if(!chosen){dialog.close();return;}
                button.disabled=true;error.hidden=true;
                try {
                    const response=await request(endpoints.preferences,'POST',{
                        venue_id:Number(dialog.dataset.venueId),notify_upcoming_events:true,
                    });
                    const targetId=Number(dialog.dataset.venueId);
                    known.set(targetId,{...known.get(targetId),state:'active',subscribed:response.subscribed});
                    paint(targetId);
                    toast('Вы подписались на обновления площадки');
                    dialog.close();
                }catch(e){
                    error.textContent=e.message;error.hidden=false;
                    toast('Не удалось оформить подписку. Попробуйте ещё раз',true);
                }finally{button.disabled=false;}
            });
        }
        dialog.dataset.venueId=String(id);
        const checking=dialog.querySelector('.mskba-favorites-confirm__checking');
        const subscription=dialog.querySelector('.mskba-favorites-confirm__subscription');
        const offer=dialog.querySelector('.mskba-favorites-confirm__offer');
        const error=dialog.querySelector('.mskba-favorites-confirm__error');
        checking.hidden=false;subscription.hidden=true;offer.hidden=true;error.hidden=true;
        dialog.querySelector('input[type=checkbox]').checked=true;
        if(dialog.open)dialog.close();
        dialog.showModal();
        void checkSubscription(id).then(subscribed=>{
            if(!dialog.open||Number(dialog.dataset.venueId)!==id)return;
            known.set(id,{...known.get(id),subscribed});
            checking.hidden=true;subscription.hidden=!subscribed;offer.hidden=subscribed;
        }).catch(e=>{
            if(!dialog.open||Number(dialog.dataset.venueId)!==id)return;
            checking.hidden=true;error.hidden=false;
            error.textContent='Не удалось проверить текущую подписку. '+e.message;
        });
    }
    async function toggle(id) {
        if(pending.has(id)||!known.has(id))return;
        pending.add(id);paint(id);
        try{
            const result=await request(endpoints.toggle,'POST',{type:'venue',id});
            known.set(id,{
                state:result.state,
                notify_upcoming_events:Boolean(result.notify_upcoming_events),
                count:Number(result.count ?? known.get(id)?.count ?? 0),
            });
            toast(result.state==='active'?'Площадка добавлена в избранное':'Площадка убрана из избранного');
            if(result.state==='active')successDialog(id);
        }catch(e) {
            let alert=document.querySelector('[data-favorites-inline-error]');
            if(!alert){alert=document.createElement('p');alert.dataset.favoritesInlineError='1';alert.className='error mskba-favorite-feedback';alert.setAttribute('role','alert');document.body.appendChild(alert);}
            alert.textContent=e.message;
            setTimeout(()=>alert.remove(),5000);
            toast('Не удалось изменить избранное. Попробуйте ещё раз',true);
        }finally{pending.delete(id);paint(id);}
    }
    // Play intent dialog updates favorites through the same backend endpoints;
    // synchronize any visible catalog/card hearts without a reload.
    window.addEventListener('mskba:venue-favorite:updated',event=>{
        const id=Number(event.detail?.venueId);
        if(!Number.isInteger(id)||id<=0)return;
        pending.delete(id);
        known.set(id,{
            ...known.get(id),
            state:event.detail.state,
            count:Number(event.detail.count??known.get(id)?.count??0),
        });
        paint(id);
    });
    document.addEventListener('click',e=>{
        const b=e.target.closest?.('[data-favorite-venue]');
        if(!b)return;
        e.preventDefault();e.stopPropagation();
        void toggle(Number(b.dataset.favoriteVenue));
    });
    function schedule(){
        if(frame!==null)return;
        frame=requestAnimationFrame(()=>{frame=null;enhance();});
    }
    document.addEventListener('DOMContentLoaded',()=>{
        enhance();
        const observer=new MutationObserver(schedule);
        observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['href']});
    });
})();
