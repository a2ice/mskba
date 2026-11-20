// Task 336: one scoped event discussion, preserving surrounding legacy/app shell.
const wall = document.querySelector('[data-event-social-wall]');
if (wall) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const feedback = wall.querySelector('[data-event-wall-feedback]');
    let lastId = Number(wall.dataset.wallLastId) || 0;
    let refreshing = false;
    const tabs = [...wall.querySelectorAll('[data-event-wall-tab]')];
    const panels = [...wall.querySelectorAll('[data-event-wall-panel]')];
    const showTab = (tab) => {
        tabs.forEach(item => {
            const selected = item.dataset.eventWallTab === tab;
            item.setAttribute('aria-selected', String(selected));
            item.classList.toggle('is-active', selected);
        });
        panels.forEach(panel => {panel.hidden = panel.dataset.eventWallPanel !== tab;});
    };
    tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.eventWallTab)));

    const message = (text, error=false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.hidden = !text;
        feedback.classList.toggle('is-error', error);
    };
    const request = async (url, method='GET', data=null) => {
        const res = await fetch(url, {
            method, credentials:'same-origin',
            headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest',
                'X-CSRF-TOKEN':csrf,...(data!==null?{'Content-Type':'application/json'}:{})},
            ...(data!==null?{body:JSON.stringify(data)}:{}),
        });
        let json;
        try {json=await res.json();}catch {json={};}
        if (!res.ok) {
            const error = new Error(json.message || (res.status===419
                ? 'Сессия истекла. Обнови страницу.' : 'Не удалось сохранить изменение.'));
            error.retryAfter = json.retry_after || res.headers.get('Retry-After');
            throw error;
        }
        return json;
    };
    const counts = (values) => {
        if (!values) return;
        for(const key of ['confirmed','tentative','left']){
            const node=wall.querySelector('[data-wall-count="'+key+'"]');
            if(node)node.textContent=String(values[key]??0);
        }
        const shared=document.querySelector('[data-event-confirmed-count]');
        if(shared){
            const max=Number(document.querySelector('[data-event-participants-surface]')?.dataset.maxParticipants);
            shared.textContent=String(values.confirmed??0)+(max>0?'/'+max:'');
        }
    };
    function append(entry) {
        if (!entry || !Number.isInteger(Number(entry.id))) return;
        if(entry.deleted){wall.querySelectorAll('[data-event-wall-entry="'+entry.id+'"]').forEach(li=>li.remove());return;}
        for (const list of wall.querySelectorAll('[data-event-wall-list]')) {
            if (list.dataset.eventWallList==='history' && !entry.system) continue;
            if (list.querySelector('[data-event-wall-entry="'+entry.id+'"]')) continue;
            list.querySelectorAll('[data-event-wall-empty]').forEach(node=>node.remove());
            const li=document.createElement('li');
            li.className='event-social-wall__entry '+(entry.system?'is-system':'is-comment')+(entry.deleted?' is-deleted':'');
            li.dataset.eventWallEntry=String(entry.id);
            const head=document.createElement('div');head.className='event-social-wall__entry-head';
            const label=document.createElement('strong');label.textContent=entry.system?'Событие':entry.actor;
            const time=document.createElement('time');time.textContent=(entry.date||'')+(entry.edited?' (изменено)':'');
            head.append(label,time);
            const text=document.createElement('p');
            text.className='event-social-wall__entry-text'+(entry.deleted&&!entry.system?' is-struck':'');
            text.textContent=entry.text||'';
            li.append(head,text);
            if(entry.deleted&&!entry.system){
                const small=document.createElement('small');
                small.className='event-social-wall__muted';
                small.textContent='Сообщение удалено автором';
                li.append(small);
            }
            if(!entry.system&&!entry.deleted&&entry.can_delete){
                const del=document.createElement('button');del.type='button';
                del.className='event-social-wall__delete';del.textContent='Удалить';
                del.dataset.wallDeleteUrl=entry.delete_url;
                li.append(del);
            }
            list.append(li);
        }
        lastId=Math.max(lastId,Number(entry.id));
        wall.dataset.wallLastId=String(lastId);
    }
    async function refresh() {
        if(refreshing || document.visibilityState==='hidden')return;
        refreshing=true;
        try{
            const url=new URL(wall.dataset.wallIndexUrl,location.href);
            url.searchParams.set('after_id',String(lastId));
            const result=await request(url.toString());
            (result.entries||[]).forEach(append);
            counts(result.counts);
        }catch(e){ /* passive polling must not overwrite user-facing form errors */ }
        finally{ refreshing=false; }
    }
    const form=wall.querySelector('[data-event-wall-form]');
    const field=form?.querySelector('[name="body"]');
    field?.addEventListener('input',()=>{
        const chars=wall.querySelector('[data-event-wall-chars]');
        if(chars)chars.textContent=String([...field.value].length);
    });
    form?.addEventListener('submit',async event=>{
        event.preventDefault();
        const body=field?.value?.trim()||'';
        if(!body)return;
        const submit=form.querySelector('[type="submit"]');
        submit.disabled=true;
        message('');
        try{
            const id=globalThis.crypto?.randomUUID?.();
            if(!id)throw new Error('Безопасное соединение требуется для публикации.');
            const result=await request(wall.dataset.wallStoreUrl,'POST',{body,client_request_id:id});
            append(result.entry);
            field.value='';
            wall.querySelector('[data-event-wall-chars]').textContent='0';
            message('Сообщение отправлено.');
            await refresh();
        }catch(e){message(e.message,true);}
        finally{submit.disabled=false;}
    });
    wall.addEventListener('click',async event=>{
        const button=event.target.closest('[data-wall-delete-url]');
        if(!button || button.disabled)return;
        button.disabled=true;
        try{
            const result=await request(button.dataset.wallDeleteUrl,'DELETE');
            wall.querySelectorAll('[data-event-wall-entry="'+result.entry.id+'"]').forEach(li=>li.remove());
            message('Сообщение удалено.');
        }catch(e){button.disabled=false;message(e.message,true);}
    });

    // Existing RSVP forms remain the source of truth. Intercept only on this
    // page and keep ordinary form submission as a no-JS fallback.
    document.querySelectorAll('form[action*="/participants/me"]').forEach(form=>{
        if(form.closest('[data-native-event-page]'))return;
        if(!form.querySelector('[name="status"]'))return;
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            const status=form.querySelector('[name="status"]').value;
            const button=form.querySelector('[type="submit"]');
            if(button?.disabled)return;
            button.disabled=true;
            message('');
            try {
                const data=await request(form.action,'PATCH',{status});
                counts(data.counts);
                document.querySelectorAll('.event-response__button').forEach(item=>
                    item.classList.toggle('is-active',item.closest('form')?.querySelector('[name="status"]')?.value===data.status));
                await refresh();
                message('Ответ на участие сохранён.');
            }catch(e){message(e.message,true);}
            finally{button.disabled=false;}
        });
    });
    window.setInterval(refresh,15000);
}
