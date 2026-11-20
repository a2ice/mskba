import '../../../css/favorite-action-dialog.css';

const labels = {
    venue: ['площадку', 'Площадка добавлена в избранное.'],
    event: ['мероприятие', 'Мероприятие добавлено в избранное.'],
    team: ['команду', 'Команда добавлена в избранное.'],
    product: ['товар', 'Товар добавлен в избранное.'],
};
const storageKey = 'mskba:favorite:pending:v1';
const currentPath = () => location.pathname + location.search + location.hash;
const authMeta = () => document.querySelector('meta[name="mskba-authenticated"]');
const authenticated = () => authMeta()?.content === '1';
const modal = () => document.querySelector('[data-favorite-action-dialog]');
const inFlight = new Set();
let intent = null;
const clear = () => { try { sessionStorage.removeItem(storageKey); } catch {} };
const toast = message => window.dispatchEvent(new CustomEvent('mskba:action-toast',{detail:{message,failed:true}}));

function show(message, action) {
    const dialog = modal();
    if (!dialog) { toast(message); return; }
    intent = action;
    dialog.querySelector('[data-favorite-action-text]').textContent = message;
    dialog.querySelector('[data-favorite-action-submit]').textContent = action ? 'Войти' : 'Понятно';
    if (!dialog.open) dialog.showModal();
}
function requireAuth(type,id) {
    show('Для того чтобы добавить '+labels[type][0]+' в избранное, необходимо авторизоваться',{type,id});
}
function publish(type,id,result) {
    window.dispatchEvent(new CustomEvent('mskba:favorites:changed',{detail:{count:result.favorites_count}}));
    if(type==='venue')window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',{detail:{venueId:id,state:result.state,count:result.count}}));
    document.querySelectorAll('[data-favorite-type][data-favorite-id]').forEach(node=>{
        if(node.dataset.favoriteType===type&&Number(node.dataset.favoriteId)===id){
            node.setAttribute('aria-pressed','true');
            node.title='Убрать из избранного';
            node.setAttribute('aria-label',node.title);
        }
    });
}
export async function addFavorite(type,id) {
    id=Number(id);
    if(!Object.hasOwn(labels,type)||!Number.isSafeInteger(id)||id<1)return false;
    if(!authenticated()){requireAuth(type,id);return false;}
    const key=type+':'+id;
    if(inFlight.has(key))return false;
    inFlight.add(key);
    try {
        const response=await fetch('/favorites/toggle',{method:'POST',credentials:'same-origin',headers:{
            Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'',
        },body:JSON.stringify({type,id,action:'add'})});
        if(response.status===401){authMeta()?.setAttribute('content','0');requireAuth(type,id);return false;}
        if(!response.ok)throw Error('Не удалось добавить в избранное. Попробуйте ещё раз.');
        const result=await response.json();
        if(result.state!=='active')throw Error('Не удалось подтвердить добавление в избранное.');
        publish(type,id,result);
        show(labels[type][1],null);
        return true;
    }catch(error){toast(error.message);return false;}
    finally{inFlight.delete(key);}
}
function startAuth(action) {
    try { sessionStorage.setItem(storageKey,JSON.stringify({...action,path:currentPath(),at:Date.now()})); }
    catch { toast('Не удалось сохранить действие для входа.'); return; }
    if(document.getElementById('mskba-auth-dialog-root')){
        window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
    }else{
        const trigger=document.querySelector('[data-favorite-classic-auth]');
        if(trigger){trigger.dataset.authRedirectUrl=currentPath();trigger.click();}
        else{clear();toast('Не удалось открыть окно входа.');}
    }
}
function resume() {
    if(!authenticated()||document.querySelector('[data-onboarding-pending]'))return;
    let pending;
    try{pending=JSON.parse(sessionStorage.getItem(storageKey)||'null');}catch{clear();return;}
    if(!pending)return;
    if(!Object.hasOwn(labels,pending.type)||!Number.isSafeInteger(pending.id)||pending.id<1||
        !Number.isFinite(pending.at)||Date.now()-pending.at>15*60*1000||
        typeof pending.path!=='string'||!pending.path.startsWith('/')||pending.path.startsWith('//')){
        clear();return;
    }
    if(pending.path!==currentPath()){
        const target=new URL(pending.path,location.origin);
        if(target.origin!==location.origin){clear();return;}
        location.assign(target.href);return;
    }
    clear();
    void addFavorite(pending.type,pending.id);
}
function init(){
    const dialog=modal();if(!dialog)return;
    dialog.querySelector('[data-favorite-action-close]').addEventListener('click',()=>dialog.close());
    dialog.querySelector('[data-favorite-action-submit]').addEventListener('click',()=>{
        const action=intent;intent=null;dialog.close();if(action)startAuth(action);
    });
    let outside=false;
    dialog.addEventListener('pointerdown',event=>{outside=event.target===dialog;});
    dialog.addEventListener('pointerup',event=>{if(outside&&event.target===dialog)dialog.close();outside=false;});
    dialog.addEventListener('pointercancel',()=>{outside=false;});
    dialog.addEventListener('close',()=>{intent=null;});
    if(authenticated())resume();else clear();
}
window.addEventListener('mskba:auth:success',()=>{authMeta()?.setAttribute('content','1');resume();});
window.addEventListener('mskba:auth:cancel',clear);
window.addEventListener('mskba:onboarding-completed',resume);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
