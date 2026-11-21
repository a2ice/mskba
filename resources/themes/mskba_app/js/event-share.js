const dialog=document.querySelector('[data-event-share-internal]');
if(dialog){
    const query=dialog.querySelector('[data-event-share-query]'),results=dialog.querySelector('[data-event-share-results]'),status=dialog.querySelector('[data-event-share-status]'),send=dialog.querySelector('[data-event-share-send]');
    const selected=new Map();let timer,controller;
    const request=async(url,method='GET',body=null)=>{
        const res=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},...(body?{body:JSON.stringify(body)}:{}),...(method==='GET'&&controller?{signal:controller.signal}:{})});
        if(!res.ok)throw new Error('Действие недоступно.');return res.json();
    };
    const sync=()=>{send.disabled=!selected.size;send.textContent=selected.size?'Поделиться ('+selected.size+')':'Поделиться';};
    const auth=()=>{dialog.close();window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));};
    let pending=false;
    document.querySelector('[data-event-dialog-next="event-share-internal"]')?.addEventListener('click',e=>{
        if(dialog.dataset.auth!=='1'){e.preventDefault();e.stopImmediatePropagation();e.currentTarget.closest('dialog')?.close();pending=true;auth();}
    },true);
    window.addEventListener('mskba:auth:cancel',()=>{pending=false;});
    window.addEventListener('mskba:auth:success',()=>{dialog.dataset.auth='1';if(pending){pending=false;dialog.showModal();}});
    query.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();results.replaceChildren();const q=query.value.trim();if(q.length<2)return;
        timer=setTimeout(async()=>{controller=new AbortController();try{const url=new URL(dialog.dataset.search,location.href);url.searchParams.set('q',q);const data=await request(url);for(const user of data.users||[]){const lab=document.createElement('label'),box=document.createElement('input');box.type='checkbox';box.checked=selected.has(user.id);box.addEventListener('change',()=>{box.checked?selected.set(user.id,user.name):selected.delete(user.id);sync();});lab.append(box,document.createTextNode(' '+user.name));results.append(lab);}status.textContent=results.children.length?'':'Ничего не найдено';}catch(e){if(e.name!=='AbortError')status.textContent=e.message;}},250);
    });
    send.addEventListener('click',async()=>{send.disabled=true;for(const [id] of [...selected]){try{await request(dialog.dataset.send.replace('__USER__',String(id)),'POST',{body:dialog.dataset.url});selected.delete(id);}catch(e){status.textContent='Не удалось отправить всем выбранным получателям.';sync();return;}}status.textContent='Ссылка отправлена.';results.replaceChildren();sync();});
}
