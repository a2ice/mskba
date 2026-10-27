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
    const getName = (state) => state==='active'?'Убрать из избранного':'Добавить в избранное';
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
    // The shared action layer owns presentation, stacking and dismissal.
    const toast = (message, failed = false) => window.dispatchEvent(
        new CustomEvent('mskba:action-toast', {detail:{message, failed}})
    );
    const contextNames={
        venue:{subject:'Площадка',added:'Площадка добавлена в избранное',removed:'Площадка убрана из избранного'},
        event:{subject:'Мероприятие',added:'Мероприятие добавлено в избранное',removed:'Мероприятие убрано из избранного'},
        team:{subject:'Команда',added:'Команда добавлена в избранное',removed:'Команда убрана из избранного'},
        user:{subject:'Пользователь',added:'Пользователь добавлен в избранное',removed:'Пользователь убран из избранного'},
    };
    const pendingGeneric=new Set();
    async function toggle(id) {
        if(pending.has(id)||!known.has(id))return;
        pending.add(id);paint(id);
        try{
            const result=await request(endpoints.toggle,'POST',{type:'venue',id});
            known.set(id,{
                state:result.state,
                notify_upcoming_events:Boolean(result.notify_upcoming_events),
                count:Number(result.count??known.get(id)?.count??0),
            });
            if(result.state==='active') {
                window.dispatchEvent(new CustomEvent('mskba:subscription-dialog:open',{
                    detail:{source:'favorite',type:'venue',id},
                }));
            } else {
                toast(contextNames.venue.removed);
            }
        }catch(e){toast(e.message||'Не удалось изменить избранное.',true);}
        finally{pending.delete(id);paint(id);}
    }
    async function toggleGeneric(type,id,name,button) {
        const key=type+':'+id;
        if(pendingGeneric.has(key))return;
        pendingGeneric.add(key);
        if(button)button.disabled=true;
        try{
            const result=await request(endpoints.toggle,'POST',{type,id});
            const words=contextNames[type];
            if(result.state!=='active')toast(words?.removed||'Убрано из избранного');
            if(button) {
                button.setAttribute('aria-pressed',String(result.state==='active'));
                button.title=result.state==='active'?'Убрать из избранного':'Добавить в избранное';
            }
            if(result.state==='active')window.dispatchEvent(new CustomEvent('mskba:subscription-dialog:open',{
                detail:{source:'favorite',type,id,name},
            }));
        }catch(e){toast(e.message||'Не удалось изменить избранное.',true);}
        finally{pendingGeneric.delete(key);if(button)button.disabled=false;}
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
    document.addEventListener('click',e=>{
        const target=e.target.closest?.('[data-favorite-type][data-favorite-id]');
        if(!target || target.matches('[data-favorite-venue]'))return;
        const type=target.dataset.favoriteType,id=Number(target.dataset.favoriteId);
        if(!Number.isInteger(id)||id<=0||!type)return;
        e.preventDefault();e.stopPropagation();
        void toggleGeneric(type,id,target.dataset.favoriteName||'',target);
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
