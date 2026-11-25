import { addFavorite } from './favorite-action.js';
// Single-click add, explicit confirmation to remove. Same UX in both themes.
(() => {
    const modal=document.querySelector('[data-venue-favorite-dialog]');
    const buttons=[...document.querySelectorAll('[data-venue-favorite-main]')];
    const footerButtons=[...document.querySelectorAll('[data-venue-favorite-footer]')];
    const followSections=[...document.querySelectorAll('[data-venue-favorite-follow]')];
    if(!modal||!buttons.length)return;
    const id=Number(modal.dataset.venueId);
    const title=modal.querySelector('[data-venue-favorite-title]');
    const description=modal.querySelector('[data-venue-favorite-description]');
    const error=modal.querySelector('[data-venue-favorite-error]');
    const submit=modal.querySelector('[data-venue-favorite-submit]');
    let state='unknown', mode='info', busy=false, statusGeneration=0;
    const isAuthenticated=()=>document.querySelector('meta[name="mskba-authenticated"]')?.content==='1'
        || document.querySelector('[data-venue-play-dialog]')?.dataset.authenticated==='1';
    // Public venue total, not the current user's header favorites count.
    let venueFavoriteCount=Math.max(0,Number(buttons[0].dataset.favoriteCount)||0);
    const setVenueCount=value=>{
        if(value===null||value===undefined||value==='')return;
        const numeric=Number(value);
        if(!Number.isSafeInteger(numeric)||numeric<0)return;
        venueFavoriteCount=numeric;
        paint();
    };
    const request=async(url,method='GET',body=null)=>{
        const res=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{
            'Accept':'application/json','Content-Type':'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'',
        },...(body===null?{}:{body:JSON.stringify(body)})});
        const result=await res.json().catch(()=>({}));
        if(!res.ok)throw Error(result.message||'Не удалось обновить избранное.');
        return result;
    };
    const paint=()=>{
        const saved=state==='active';
        buttons.forEach(button=>{
            const label=button.querySelector?.('[data-venue-favorite-main-label]')??button;
            label.textContent=(saved?'В избранном':'В избранное')+(venueFavoriteCount>0?' ('+venueFavoriteCount+')':'');
            button.setAttribute('aria-pressed',String(saved));
            button.disabled=busy;
        });
        const loading=state==='unknown'&&isAuthenticated();
        const failed=state==='error'&&isAuthenticated();
        for(const button of footerButtons){
            button.querySelector('[data-venue-favorite-footer-label]').textContent=saved?'Уже в избранном':loading?'Проверяем избранное…':failed?'Повторить':'Добавить в избранное';
            button.disabled=busy||saved||loading;
            button.setAttribute('aria-pressed',String(saved));
        }
        for(const section of followSections){
            const message=section.querySelector('[data-venue-favorite-follow-message]');
            const label=section.querySelector('[data-venue-favorite-follow-label]');
            const action=section.querySelector('[data-venue-favorite-follow-action]');
            const canCreateEvent=saved&&Boolean(section.dataset.createEventUrl);
            section.dataset.state=saved?'active':loading?'loading':failed?'error':'none';
            message.textContent=saved
                ? (canCreateEvent ? 'Площадка уже в избранном. Создайте здесь игру и пригласите участников.' : 'Площадка в избранном — следите здесь за новыми играми.')
                : loading?'Проверяем, сохранена ли площадка…'
                : failed?'Не удалось проверить избранное. Попробуйте ещё раз.'
                : 'Добавьте площадку в избранное, чтобы следить за новыми играми.';
            label.textContent=saved?(canCreateEvent?'Создать мероприятие':'В избранном'):loading?'Проверяем избранное…'
                : failed?'Повторить':'Добавить в избранное';
            action.disabled=busy||(saved&&!canCreateEvent)||loading;
            action.setAttribute('aria-pressed',String(saved));
        }
    };
    const fail=message=>{error.textContent=message||'';error.hidden=!message;};
    const show=(nextMode)=>{
        mode=nextMode;fail('');
        title.textContent='Избранное';
        description.textContent=mode==='info'
            ?'Площадка добавлена в избранное.'
            :'Вы уверены, что хотите убрать площадку из избранного?';
        submit.textContent=mode==='info'?'Понятно':'Убрать';
        if(!modal.open)modal.showModal();
    };
    const toggle=async()=>{
        busy=true;paint();submit.disabled=true;fail('');
        try {
            if(state!=='active'){await addFavorite('venue',id);return;}
            const result=await request(modal.dataset.toggleUrl,'POST',{type:'venue',id,action:'remove'});
            state=result.state;
            setVenueCount(result.count);
            paint();
            window.dispatchEvent(new CustomEvent('mskba:favorites:changed',{detail:{count:result.favorites_count}}));
            window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',{
                detail:{venueId:id,state:result.state,count:result.count},
            }));
            if(state==='active')show('info');
            else{
                modal.close();
                window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message:'Площадка убрана из избранного'}}));
            }
        }catch(e){
            if(mode==='confirm')fail(e.message);
            else window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message:e.message,failed:true}}));
        }finally{busy=false;submit.disabled=false;paint();}
    };
    const ensureStatus=async(force=false)=>{
        if(!force&&state!=='unknown'&&state!=='error')return;
        const generation=++statusGeneration;
        state='unknown';paint();
        try{
            const url=new URL(modal.dataset.statusUrl,location.href);
            url.searchParams.append('ids[]',String(id));
            const result=await request(url);
            if(generation!==statusGeneration)return;
            state=result.data?.[id]?.state||'none';
            setVenueCount(result.counts?.[id]);
            paint();
        }catch(error){
            if(generation===statusGeneration){state='error';paint();}
            throw error;
        }
    };
    const follow=async(event,section=null)=>{
            if(busy)return;
            if(state==='active'){
                const url=section?.dataset.createEventUrl;
                if(url)window.location.assign(url);
                return;
            }
            if(state==='error'&&isAuthenticated()){
                try{await ensureStatus(true);}
                catch(error){window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message:error.message,failed:true}}));}
                return;
            }
            if(isAuthenticated()&&state==='unknown'){
                try{await ensureStatus();}
                catch(error){window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message:error.message,failed:true}}));return;}
                if(state==='active')return;
            }
            // Close the source dialog before the shared favorite/auth/info dialog opens.
            // This also prevents native dialog stacking and preserves the existing login-resume flow.
            const source=event.currentTarget.closest('dialog');
            if(source?.open)source.close();
            busy=true;paint();
            try{await addFavorite('venue',id);}
            finally{busy=false;paint();}
    };
    followSections.forEach(section=>section.querySelector('[data-venue-favorite-follow-action]')
        ?.addEventListener('click',event=>follow(event,section)));
    footerButtons.forEach(button=>button.addEventListener('click',event=>follow(event)));
    buttons.forEach(button=>button.addEventListener('click',async()=>{
        if(busy)return;
        busy=true;paint();
        try{await ensureStatus();}catch(e){busy=false;paint();return void window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message:e.message,failed:true}}));}
        busy=false;paint();
        if(state==='active')show('confirm');
        else void toggle();
    }));
    submit.addEventListener('click',()=>{
        if(busy)return;
        if(mode==='confirm')void toggle();else modal.close();
    });
    modal.querySelector('[data-venue-favorite-close]').addEventListener('click',()=>modal.close());
    window.addEventListener('mskba:venue-favorite:updated',event=>{
        if(Number(event.detail?.venueId)!==id)return;
        statusGeneration++; // Ignore an in-flight status response predating this mutation.
        state=event.detail.state;
        setVenueCount(event.detail.count);
        paint();
    });
    let downOutside=false;
    modal.addEventListener('pointerdown',event=>{downOutside=event.target===modal;});
    modal.addEventListener('pointerup',event=>{if(downOutside&&event.target===modal)modal.close();downOutside=false;});
    modal.addEventListener('pointercancel',()=>{downOutside=false;});
    window.addEventListener('mskba:auth:success',()=>{
        // Login can complete in-place without a full reload; the actual add event
        // still wins over any earlier GET, via statusGeneration.
        if(state!=='active')void ensureStatus(true).catch(()=>{});
    });
    paint();
    void ensureStatus().catch(()=>{});
})();
