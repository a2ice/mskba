import {mountEventGameEditor} from '../../../js/event-game-editor.js';

const root=document.querySelector('[data-event-planned-games]');
const editorDialog=document.getElementById('event-page-game-editor');
const deleteDialog=document.getElementById('event-page-game-plan-delete');
if(root){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const read=async(response)=>{
        const data=await response.json().catch(()=>({}));
        if(!response.ok){
            const error=Error(Object.values(data.errors||{}).flat()[0]||data.message||'Не удалось выполнить действие.');
            error.errors=data.errors||{};throw error;
        }
        return data;
    };
    const mutate=async(url,method,value)=>read(await fetch(url,{
        method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf(),'X-Requested-With':'XMLHttpRequest'},
        ...(value?{body:JSON.stringify(value)}:{}),
    }));
    root.addEventListener('click',async event=>{
        const trigger=event.target.closest('[data-planned-invite-action]');
        if(!trigger||!root.contains(trigger)||trigger.disabled)return;
        const row=trigger.closest('[data-planned-game-row]');
        const error=row?.querySelector('[data-planned-invite-error]');
        if(error)error.hidden=true;
        const actions=[...row.querySelectorAll('[data-planned-invite-action]')];
        actions.forEach(action=>action.disabled=true);
        try{
            await mutate(trigger.dataset.url,trigger.dataset.method,trigger.dataset.payload?JSON.parse(trigger.dataset.payload):undefined);
            location.reload();
        }catch(e){if(error){error.textContent=e.message;error.hidden=false;}actions.forEach(action=>action.disabled=false);}
    });
    if(editorDialog&&deleteDialog){
    const editor=mountEventGameEditor(editorDialog,{
        async searchTeams(query,exclude){
            const url=new URL(root.dataset.searchUrl,location.origin);
            url.searchParams.set('q',query);if(exclude!==null)url.searchParams.set('exclude_id',String(exclude));
            const data=await read(await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'}));
            return Array.isArray(data.teams)?data.teams:[];
        },
        async onSave(value,teams,row){
            await mutate(row?row.dataset.updateUrl:root.dataset.createUrl,row?'PUT':'POST',value);
            location.reload();
        },
    });
    let selected=null,deleting=false;
    const confirm=deleteDialog.querySelector('[data-game-plan-delete-confirm]');
    const error=deleteDialog.querySelector('[data-game-plan-delete-error]');
    root.addEventListener('click',event=>{
        const trigger=event.target.closest('button');
        if(!trigger||!root.contains(trigger))return;
        if(trigger.matches('[data-planned-game-add]')){editor.open();return;}
        const row=trigger.closest('[data-planned-game-row]');
        if(!row)return;
        const config=JSON.parse(row.dataset.gameConfig),teams=JSON.parse(row.dataset.gameTeams);
        if(trigger.matches('[data-planned-game-edit]'))editor.open(config,teams,row);
        if(trigger.matches('[data-planned-game-delete]')){
            selected=row;error.hidden=true;
            deleteDialog.querySelector('[data-game-plan-delete-name]').textContent=config.title;
            deleteDialog.querySelector('[data-game-plan-delete-info]').textContent=[teams.a?.name,teams.b?.name].filter(Boolean).join(' — ');
            deleteDialog.showModal();
        }
    });
    deleteDialog.querySelectorAll('[data-game-plan-delete-cancel]').forEach(button=>button.addEventListener('click',()=>{if(!deleting)deleteDialog.close();}));
    deleteDialog.addEventListener('cancel',e=>{if(deleting)e.preventDefault();});
    confirm.addEventListener('click',async()=>{
        if(deleting||!selected)return;
        deleting=true;confirm.disabled=true;error.hidden=true;
        try{await mutate(selected.dataset.deleteUrl,'DELETE');deleteDialog.close();location.reload();}
        catch(e){error.textContent=e.message;error.hidden=false;}
        finally{deleting=false;confirm.disabled=false;}
    });
    }
}
