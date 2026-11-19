// Single-click add, explicit confirmation to remove. Same UX in both themes.
(() => {
    const modal=document.querySelector('[data-venue-favorite-dialog]');
    const buttons=[...document.querySelectorAll('[data-venue-favorite-main]')];
    if(!modal||!buttons.length)return;
    const id=Number(modal.dataset.venueId);
    const title=modal.querySelector('[data-venue-favorite-title]');
    const description=modal.querySelector('[data-venue-favorite-description]');
    const error=modal.querySelector('[data-venue-favorite-error]');
    const submit=modal.querySelector('[data-venue-favorite-submit]');
    let state='unknown', mode='info', busy=false;
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
        buttons.forEach(button=>{
            const saved=state==='active';
            button.textContent=saved?'В избранном':'В избранное';
            button.setAttribute('aria-pressed',String(saved));
            button.disabled=busy;
        });
    };
    const fail=message=>{error.textContent=message||'';error.hidden=!message;};
    const show=(nextMode)=>{
        mode=nextMode;fail('');
        title.textContent=mode==='info'?'Площадка теперь в избранном':'Убрать из избранного?';
        description.textContent=mode==='info'
            ?'Добавь площадку в избранное, чтобы вернуться к ней позже.'
            :'Вы уверены, что хотите убрать площадку из избранного?';
        submit.textContent=mode==='info'?'Понятно':'Убрать';
        if(!modal.open)modal.showModal();
    };
    const toggle=async()=>{
        busy=true;paint();submit.disabled=true;fail('');
        try {
            const result=await request(modal.dataset.toggleUrl,'POST',{type:'venue',id});
            state=result.state;paint();
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
    const ensureStatus=async()=>{
        if(state!=='unknown')return;
        const url=new URL(modal.dataset.statusUrl,location.href);
        url.searchParams.append('ids[]',String(id));
        const result=await request(url);
        state=result.data?.[id]?.state||'none';paint();
    };
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
        state=event.detail.state;paint();
    });
    let downOutside=false;
    modal.addEventListener('pointerdown',event=>{downOutside=event.target===modal;});
    modal.addEventListener('pointerup',event=>{if(downOutside&&event.target===modal)modal.close();downOutside=false;});
    modal.addEventListener('pointercancel',()=>{downOutside=false;});
    void ensureStatus().catch(()=>{});
})();
