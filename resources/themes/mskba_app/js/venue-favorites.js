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
    const svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 5.5a5.25 5.25 0 0 0 -7.5 0a5.25 5.25 0 0 0 -7.5 7.5l7.5 7.5a5.25 5.25 0 0 0 0 -7.5z"/></svg>';
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
        document.querySelectorAll('[data-favorite-venue]').forEach(btn=>{
            if(Number(btn.dataset.favoriteVenue)!==id) return;
            btn.classList.add('mskba-favorite-heart');
            if (!btn.querySelector('svg path')) btn.innerHTML = svg;
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
            if(!id||card.querySelector('[data-favorite-venue]'))return;
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
                known.set(id,data.data?.[id]||{state:'none',notify_upcoming_events:false});
                pending.delete(id);paint(id);
            }
        }catch{
            ids.forEach(id=>pending.delete(id));
            // Prefer leaving the original state unknown over pretending saved=false.
        }finally{
            loading=false;if(pending.size)setTimeout(()=>void hydrate(),0);
        }
    }
    function successDialog(id) {
        if(!dialog) {
            dialog=document.createElement('dialog');
            dialog.className='mskba-favorites-confirm';
            dialog.setAttribute('aria-labelledby','mskba-favorites-confirm-title');
            dialog.innerHTML = '<div class="mskba-favorites-confirm__header"><h2 id="mskba-favorites-confirm-title">Площадка добавлена в избранное</h2><button type="button" class="mskba-favorites-confirm__close" aria-label="Закрыть">×</button></div>'+
                '<p>Ты сможешь найти площадку в разделе «Избранное» личного кабинета.</p>'+
                '<label class="mskba-favorites-confirm__option"><input type="checkbox" checked><span>Получать уведомления о предстоящих мероприятиях: играх, тренировках и турнирах</span></label>'+
                '<p class="mskba-favorites-confirm__error error" role="alert" hidden></p>'+
                '<button type="button" class="button primary mskba-favorites-confirm__apply">Применить</button>';
            document.body.appendChild(dialog);
            dialog.querySelector('.mskba-favorites-confirm__close').addEventListener('click',()=>dialog.close());
            dialog.addEventListener('cancel',()=>dialog.close());
            dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
            dialog.querySelector('.mskba-favorites-confirm__apply').addEventListener('click',async()=>{
                const button=dialog.querySelector('.mskba-favorites-confirm__apply');
                const error=dialog.querySelector('.mskba-favorites-confirm__error');
                const chosen=dialog.querySelector('input[type=checkbox]').checked;
                button.disabled=true;error.hidden=true;
                try {
                    const response=await request(endpoints.preferences,'POST',{
                        venue_id:Number(dialog.dataset.venueId),
                        notify_upcoming_events:chosen,
                    });
                    const targetId=Number(dialog.dataset.venueId);
                    known.set(targetId,{state:'active',notify_upcoming_events:response.notify_upcoming_events});
                    paint(targetId);dialog.close();
                }catch(e){error.textContent=e.message;error.hidden=false;}
                finally{button.disabled=false;}
            });
        }
        dialog.dataset.venueId=String(id);
        dialog.querySelector('.mskba-favorites-confirm__error').hidden=true;
        // Propose opt-in; actual subscription is saved only after Apply.
        dialog.querySelector('input[type=checkbox]').checked=true;
        if(dialog.open)dialog.close();
        dialog.showModal();
    }
    async function toggle(id) {
        if(pending.has(id)||!known.has(id))return;
        pending.add(id);paint(id);
        try{
            const result=await request(endpoints.toggle,'POST',{type:'venue',id});
            known.set(id,{
                state:result.state,
                notify_upcoming_events:Boolean(result.notify_upcoming_events),
            });
            if(result.state==='active')successDialog(id);
        }catch(e) {
            let alert=document.querySelector('[data-favorites-inline-error]');
            if(!alert){alert=document.createElement('p');alert.dataset.favoritesInlineError='1';alert.className='error mskba-favorite-feedback';alert.setAttribute('role','alert');document.body.appendChild(alert);}
            alert.textContent=e.message;
            setTimeout(()=>alert.remove(),5000);
        }finally{pending.delete(id);paint(id);}
    }
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
