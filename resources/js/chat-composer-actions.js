// Shared disclosure menu for caller-provided chat actions and contextual deletion.
export function syncChatActions(form) {
    const menu=form.querySelector('[data-chat-actions]');
    if(!menu)return false;
    const available=[...menu.querySelectorAll('button')].some(button=>!button.hidden);
    menu.hidden=form.dataset.chatCanPost!=='true';
    menu.querySelector('summary').setAttribute('aria-disabled',String(!available));
    if(!available||menu.hidden)menu.open=false;
    return available;
}

export function setChatComposerAccess(form,canPost) {
    const allowed=!!canPost;
    form.dataset.chatCanPost=String(allowed);
    const field=form.querySelector('[name="body"]');
    field.hidden=!allowed;
    field.required=allowed;
    form.querySelector('[data-venue-play-chars]').hidden=!allowed;
    form.querySelector('[type="submit"]').hidden=!allowed;
    if(!allowed) {
        form.querySelector('[data-chat-context-delete]').hidden=true;
        form.querySelector('[data-venue-play-edit-cancel]').hidden=true;
    }
    form.hidden=!allowed;
    syncChatActions(form);
}

export function bindChatActions(form) {
    const menu=form.querySelector('[data-chat-actions]');
    if(!menu)return;
    const toggle=menu.querySelector('summary');
    toggle.addEventListener('click',event=>{
        if(toggle.getAttribute('aria-disabled')==='true')event.preventDefault();
    });
    menu.addEventListener('click',event=>{
        const action=event.target.closest('button[data-chat-action],button[data-chat-context-delete]');
        if(!action||action.disabled||action.hidden)return;
        menu.open=false;
        if(action.dataset.chatAction)form.dispatchEvent(new CustomEvent('mskba:chat-action',{
            bubbles:true,detail:{id:action.dataset.chatAction,value:action.dataset.chatActionValue,slot:action.dataset.chatActionSlot}
        }));
    });
    document.addEventListener('pointerdown',event=>{if(menu.open&&!menu.contains(event.target))menu.open=false;});
    menu.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&menu.open){event.preventDefault();event.stopPropagation();menu.open=false;toggle.focus();}
    });
    syncChatActions(form);
}
