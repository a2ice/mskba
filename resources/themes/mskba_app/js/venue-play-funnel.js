// Page and venue-popup adapters: navigation, auth, and host-specific counters only.
import {mountEventDiscussion,bindBackdropDismiss} from '../../../js/event-social-wall.js';

const deleteDialog=document.querySelector('[data-venue-play-delete-dialog]');
const login=()=>{
    window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
    const legacy=document.querySelector('[data-venue-booking-auth], [data-modal-target="auth-entry-classic"]');
    if(legacy&&!document.querySelector('[data-app-auth-root]'))legacy.click();
};
const toast=(message,failed=false,dialog=null)=>window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message,failed,...(dialog?{dialog}:{})}}));

for(const page of document.querySelectorAll('[data-venue-play-page]')){
    if(!deleteDialog)continue;
    const legacy=page.hasAttribute('data-event-discussion-legacy');
    const controller=mountEventDiscussion(page,{
        deleteDialog,
        authenticated:page.dataset.authenticated==='1',
        guestResponse:login,
        toast,
        onRosterUpdate:(_pane,roster,counts)=>{
            if(!legacy||!counts)return;
            const count=document.querySelector('[data-event-confirmed-count]');
            if(count)count.textContent=String(counts.confirmed??0)+(roster.max_participants>0?'/'+roster.max_participants:'');
        },
        onParticipationSaved:(_pane,result)=>{
            if(!legacy)return;
            document.querySelectorAll('.event-response__button').forEach(button=>
                button.classList.toggle('is-active',button.closest('form')?.querySelector('[name="status"]')?.value===result.status));
        },
    });
    controller.paintButtons(page);
    void controller.refreshRoster(page);
    window.addEventListener('mskba:auth:success',()=>window.location.reload());
    if(legacy){
        // Keep legacy sport/registration-specific actions in their existing host.
        document.querySelectorAll('form[action*="/participants/me"]').forEach(form=>{
            if(form.action!==new URL(page.dataset.eventParticipationUrl,location.href).href||!form.querySelector('[name="status"]'))return;
            form.addEventListener('submit',async event=>{
                event.preventDefault();
                const button=form.querySelector('[type="submit"]');
                if(button?.disabled)return;
                if(button)button.disabled=true;
                try{await controller.respond(page,form.querySelector('[name="status"]').value);}
                finally{if(button)button.disabled=false;}
            });
        });
        // Preserve the old page's passive polling without jumping away from history.
        window.setInterval(()=>{
            if(document.visibilityState!=='hidden'&&!controller.savingPane)void controller.refreshWall(page,null,false,false);
        },15000);
    }
}

function mountVenuePopup(dialog,authNotice){
    if(!dialog||!authNotice||!deleteDialog||typeof dialog.showModal!=='function')return;
    const list=dialog.querySelector('[data-venue-play-step="list"]');
    const detail=dialog.querySelector('[data-venue-play-step="detail"]');
    const listFooter=dialog.querySelector('[data-venue-play-list-footer]');
    const detailFooter=dialog.querySelector('[data-venue-play-detail-footer]');
    const title=dialog.querySelector('[data-venue-play-title]');
    const resumeKey='mskba:venue-play-resume:'+location.pathname;
    let selectedId=null,pendingResponse=null;
    const getPane=id=>dialog.querySelector('[data-venue-play-pane="'+Number(id)+'"]');
    const saveResume=()=>{try{sessionStorage.setItem(resumeKey,JSON.stringify({id:selectedId,status:pendingResponse,at:Date.now()}));}catch{}};
    const clearResume=()=>{try{sessionStorage.removeItem(resumeKey);}catch{}};
    const guestResponse=status=>{pendingResponse=status;saveResume();dialog.close();authNotice.showModal();};
    const controller=mountEventDiscussion(dialog,{
        deleteDialog,
        authenticated:dialog.dataset.authenticated==='1',
        guestResponse,
        toast:(message,failed)=>toast(message,failed,dialog),
        onRosterUpdate:(pane,_roster,counts)=>{
            const card=dialog.querySelector('[data-venue-play-preview="'+Number(pane.dataset.venuePlayPane)+'"] [data-venue-play-card-count]');
            if(card&&counts&&Number.isInteger(counts.confirmed))card.textContent=counts.confirmed+' пойдут · Подробнее';
        },
        onParticipationSaved:()=>{pendingResponse=null;clearResume();},
    });
    function showList(){
        selectedId=null;list.hidden=false;detail.hidden=true;listFooter.hidden=false;detailFooter.hidden=true;
        title.textContent='Хочу здесь играть';
    }
    function showEvent(id){
        const pane=getPane(id);if(!pane)return;
        selectedId=Number(id);list.hidden=true;detail.hidden=false;listFooter.hidden=true;detailFooter.hidden=false;
        detailFooter.querySelectorAll('[data-venue-play-event-link]').forEach(el=>{el.hidden=Number(el.dataset.venuePlayEventLink)!==selectedId;});
        // Different event cards must open at the start of their own detail view.
        detail.scrollTop=0;
        detail.querySelectorAll('[data-venue-play-pane]').forEach(el=>{el.hidden=el!==pane;});
        title.textContent='Мероприятие';
        controller.paintButtons(pane);
        if(controller.savingPane!==pane)void controller.refreshRoster(pane);
    }
    function open(){
        if(!dialog.open)dialog.showModal();
        if(selectedId)showEvent(selectedId);else showList();
    }
    document.addEventListener('click',event=>{
        const direct=event.target.closest?.('[data-venue-play-open-event]');
        if(direct){
            if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
            const id=Number(direct.dataset.venuePlayOpenEvent);
            // Keep a real link fallback if the event is no longer available.
            if(!getPane(id))return;
            event.preventDefault();
            if(!dialog.open)dialog.showModal();
            showEvent(id);
            return;
        }
        const opener=event.target.closest?.('[data-venue-play-open]');
        if(!opener||opener.disabled)return;
        event.preventDefault();open();
    });
    dialog.querySelectorAll('[data-venue-play-preview]').forEach(button=>
        button.addEventListener('click',()=>showEvent(button.dataset.venuePlayPreview)));
    dialog.querySelector('[data-venue-play-back]')?.addEventListener('click',showList);
    dialog.querySelectorAll('[data-venue-play-close]').forEach(button=>
        button.addEventListener('click',()=>dialog.close()));
    authNotice.querySelectorAll('[data-venue-play-auth-close]').forEach(button=>
        button.addEventListener('click',()=>authNotice.close()));
    authNotice.querySelector('[data-venue-play-auth-login]')?.addEventListener('click',()=>{
        authNotice.close();
        window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
        const legacy=document.querySelector('[data-venue-booking-auth], [data-modal-target="auth-entry-classic"]');
        if(legacy&&!document.querySelector('[data-app-auth-root]'))legacy.click();
    });
    const resume=()=>{
        try{if(sessionStorage.getItem('mskba:auth:pending-flow')==='quick-event')return;}catch{}
        let saved=null;try{saved=JSON.parse(sessionStorage.getItem(resumeKey)||'null');}catch{}
        if(!saved || Date.now()-saved.at>20*60*1000){clearResume();return;}
        if(!getPane(saved.id))return;
        selectedId=Number(saved.id);pendingResponse=saved.status||null;
        open();
        if(pendingResponse){const status=pendingResponse;pendingResponse=null;void controller.respond(getPane(selectedId),status);}
        else clearResume();
    };
    window.addEventListener('mskba:auth:success',()=>{controller.setAuthenticated(true);dialog.dataset.authenticated='1';resume();});
    if(controller.authenticated)queueMicrotask(resume);

    bindBackdropDismiss(dialog);
    bindBackdropDismiss(authNotice);
}
mountVenuePopup(document.querySelector('[data-venue-play-dialog]'),document.querySelector('[data-venue-play-auth-notice]'));
