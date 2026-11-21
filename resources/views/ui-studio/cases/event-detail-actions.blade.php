<script type="module">
const root=document.querySelector('.studio-event-page');
const state=@json($state);
let isAuthenticated=state!=='guest', participation=['confirmed','tentative','editing'].includes(state)?(state==='tentative'?'tentative':'confirmed'):'left', pending=null;
const cta=root.querySelector('[data-event-participate]');
const nav=root.querySelector('[data-venue-section-nav]');
const syncNav=()=>root.style.setProperty('--studio-nav-height',nav.offsetHeight+'px');
new ResizeObserver(syncNav).observe(nav);syncNav();
const anchors=[...nav.querySelectorAll('a')];
const updateNav=()=>{let active=anchors[0];for(const a of anchors){if(document.querySelector(a.hash).getBoundingClientRect().top<=nav.getBoundingClientRect().bottom+24)active=a;}anchors.forEach(a=>a===active?a.setAttribute('aria-current','location'):a.removeAttribute('aria-current'));};
window.addEventListener('scroll',updateNav,{passive:true});updateNav();
function show(id){
    document.querySelectorAll('.studio-event-dialog[open]').forEach(d=>d.close());
    document.getElementById(id)?.showModal();
}
const syncCTA=()=>{cta.textContent=participation==='left'?'Участвовать':'Не участвовать';};
syncCTA();
const openParticipation=()=>show(participation==='left'?'studio-participate':'studio-leave');
cta.addEventListener('click',openParticipation);
root.addEventListener('studio:participate',openParticipation);
function apply(status){
    participation=status;pending=null;syncCTA();
    document.querySelectorAll('.studio-event-dialog[open]').forEach(d=>d.close());
    root.dispatchEvent(new CustomEvent('studio:participation-changed',{detail:{
        allowed:status!=='left',status,message:'superadmin '+({confirmed:'пойдёт на мероприятие.',tentative:'подумает об участии.',left:'не пойдёт на мероприятие.'}[status])
    }}));
}
document.querySelectorAll('[data-event-rsvp]').forEach(button=>button.addEventListener('click',()=>{
    const status=button.dataset.eventRsvp;
    if(!isAuthenticated){pending=status;show('studio-auth-required');return;}
    apply(status);
}));
document.querySelector('[data-event-demo-login]').addEventListener('click',()=>{
    isAuthenticated=true;if(pending)apply(pending);else show('studio-share-internal');
});
document.querySelectorAll('[data-event-open]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();show(button.dataset.eventOpen);}));
document.querySelectorAll('[data-event-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
document.querySelectorAll('.studio-event-dialog').forEach(d=>{
    let outside=null;d.addEventListener('pointerdown',e=>{outside=e.target===d?e.pointerId:null;});
    d.addEventListener('pointerup',e=>{if(outside===e.pointerId&&e.target===d)d.close();outside=null;});
    d.addEventListener('pointercancel',()=>outside=null);
});
document.querySelector('[data-studio-recipient]').addEventListener('input',e=>{
    const q=e.target.value.trim().toLowerCase();
    document.querySelectorAll('[data-studio-recipients] label').forEach(label=>label.hidden=!label.textContent.toLowerCase().includes(q));
});
document.querySelectorAll('[data-studio-external]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));
document.querySelector('[data-studio-copy]').addEventListener('click',async e=>{
    try{await navigator.clipboard.writeText('https://mskba.ru/events/28-igra-na-m-yakhromskaya');e.target.textContent='Ссылка скопирована';}catch{e.target.textContent='Скопировать ссылку';}
});
</script>
