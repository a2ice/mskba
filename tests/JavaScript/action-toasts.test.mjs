import test from 'node:test';
import assert from 'node:assert/strict';
import { initActionToasts, MAX_ACTION_TOASTS } from '../../resources/themes/mskba_app/js/action-toasts.js';
import { createNotificationToastDock } from '../../resources/themes/mskba_app/js/notification-toasts.js';
import { readFileSync } from 'node:fs';

class FakeElement {
    constructor(tag='div') {
        this.tag=tag;this.className='';this.children=[];this.parent=null;this.hidden=false;
        this.dataset={};this.attributes={};this.listeners={};this.style={setProperty(){}};
    }
    append(...nodes) { for(const node of nodes){node.remove();node.parent=this;this.children.push(node);} }
    prepend(...nodes) { for(const node of nodes){node.remove();node.parent=this;}this.children.unshift(...nodes); }
    remove(){if(this.parent){this.parent.children=this.parent.children.filter(x=>x!==this);this.parent=null;}}
    setAttribute(name,value){this.attributes[name]=value;}
    addEventListener(name,fn){this.listeners[name]=fn;}
    querySelector(selector) {
        const name=selector.slice(1);
        for(const child of this.children){
            if(child.className.split(' ').includes(name))return child;
            const nested=child.querySelector(selector);
            if(nested)return nested;
        }
        return null;
    }
    click(){this.listeners.click?.();}
}
const setup=(withRegion=true)=>{
    const region=withRegion?new FakeElement('section'):null;
    if(region){region.className='app-message-toast-region';region.hidden=true;}
    const listeners={}, timers=[];
    const doc={body:new FakeElement('body'),
        querySelector:(query)=>query==='[data-message-toast-region]'?region:
            query==='meta[name="csrf-token"]'?{content:'csrf'}:null,
        createElement:tag=>new FakeElement(tag)};
    if(region)doc.body.append(region);
    const host={addEventListener:(name,fn)=>{listeners[name]=fn;},
        setTimeout:(fn)=>{timers.push(fn);}};
    const app=initActionToasts(doc,host);
    const send=(message,failed=false)=>listeners['mskba:action-toast']({detail:{message,failed}});
    return {...app,send,timers,doc,host};
};
test('uses existing notification region, same toast classes and visible stack',()=>{
    const {region,send,size}=setup();
    send('Площадка убрана из избранного');
    assert.equal(size(),1);
    assert.equal(region.children.length,1);
    assert.equal(region.hidden,false);
    assert.match(region.children[0].className,/toast app-message-toast app-action-toast/);
    assert.equal(region.children[0].attributes.role,'status');
    assert.equal(region.children[0].querySelector('.app-message-toast__heading').textContent,'Площадка убрана из избранного');
});
test('error result is accessible and separately dismissible, no unread message id',()=>{
    const {region,send,size}=setup();
    send('Не удалось оформить подписку',true);
    const card=region.children[0];
    assert.equal(card.attributes.role,'alert');
    assert.match(card.className,/app-action-toast--error/);
    assert.equal(card.dataset.messageToastId,undefined);
    card.querySelector('.app-message-toast__close').click();
    assert.equal(size(),0);assert.equal(region.hidden,true);
});
test('temporary toast coexists with authoritative unread notification without hiding it',()=>{
    const {region,send}=setup();
    globalThis.document={querySelector:()=>({content:'csrf'}),createElement:tag=>new FakeElement(tag)};
    globalThis.window={location:{origin:'https://dev.mskba.ru'}};
    const dock=createNotificationToastDock(region,()=>{});
    dock.add({id:42,title:'Сообщение',body:'Текст',read_url:'/read/42'});
    send('Подписка оформлена');
    assert.equal(region.children.length,2);
    dock.reconcile([]);
    assert.equal(region.children.length,1);
    assert.equal(region.hidden,false,'sync must not hide a visible ephemeral action toast');
    assert.match(region.children[0].className,/app-action-toast/);
});
test('caps action toasts without evicting ordinary notifications; expiration restores hidden state',()=>{
    const {region,send,timers,size}=setup(false);
    send('Один');send('Два');send('Три');send('Четыре');
    assert.equal(size(),MAX_ACTION_TOASTS);
    assert.equal(region.children.length,MAX_ACTION_TOASTS);
    assert.equal(region.children[0].querySelector('.app-message-toast__heading').textContent,'Четыре');
    for(const fn of timers)fn();
    assert.equal(region.children.length,0);assert.equal(region.hidden,true);
});
test('favorite success has no second toast; errors and removals share dispatch',()=>{
    const favorites=readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorites.js',import.meta.url),'utf8');
    const vue=readFileSync(new URL('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue',import.meta.url),'utf8');
    assert.doesNotMatch(favorites,/mskba-action-toast-region|className='mskba-action-toast'/);
    assert.match(favorites,/else \{\s*toast\(contextNames.venue.removed\)/);
    assert.match(favorites,/if\(result.state!=='active'\)toast/);
    assert.doesNotMatch(vue,/toast\(data.state==='active'\?'Добавлено в избранное'/);
    assert.match(vue,/pendingSuccessToast=editingId.value\?'Подписка сохранена':'Подписка оформлена'/);
});
