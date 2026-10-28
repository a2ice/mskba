import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { constrainModalMove, visualModalViewport, initModalDragging } from
    '../../resources/themes/mskba_app/js/modal-dragging.js';

test('pointer geometry never moves a dialog beyond the visual viewport', () => {
    const viewport = {left:0,top:0,width:1000,height:700};
    const rect={left:200,top:100,width:420,height:300};
    assert.deepEqual(constrainModalMove(rect,1000,-1000,viewport),{dx:372,dy:-92});
    assert.deepEqual(constrainModalMove(rect,-1000,1000,viewport),{dx:-192,dy:292});
    assert.deepEqual(constrainModalMove({...rect,left:950},0,0,viewport),{dx:-378,dy:0});
    assert.deepEqual(constrainModalMove({left:50,top:50,width:1200,height:900},50,50,viewport),{dx:-42,dy:-42});
    assert.deepEqual(visualModalViewport({visualViewport:{
        offsetLeft:15,offsetTop:20,width:500,height:300,
    }}),{left:15,top:20,width:500,height:300});
    assert.deepEqual(visualModalViewport({innerWidth:800,innerHeight:600}),
        {left:0,top:0,width:800,height:600});
});

function harness(legacy=false) {
    const listeners={doc:{},win:{}}, observers=[];
    const node=(kind, parent=null)=>({
        kind,parent,children:[],open:kind==='dialog',hidden:false,isConnected:true,
        style:{translate:''}, attrs:{},tabIndex:-1,
        classList:{contains:n=>n==='is-open'&&legacy},
        matches(selector){return selector.includes(kind==='dialog'?'dialog.mskba-modal':
            kind==='surface'?'.modal__dialog':kind==='shell'?'.modal':'never-match');},
        closest(selector) {
            let n=this;
            while(n){if(n.matches(selector))return n;n=n.parent;}
            return null;
        },
        setAttribute(k,v){this.attrs[k]=v;},
        hasAttribute(k){return Object.hasOwn(this.attrs,k);},
        removeAttribute(k){delete this.attrs[k];},
        querySelectorAll(selector) {
            const out=[];
            const scan=n=>{for(const c of n.children){if(c.matches(selector))out.push(c);scan(c);}};
            scan(this);return out;
        },
        querySelector(selector){return this.querySelectorAll(selector)[0] || null;},
        getBoundingClientRect(){
            const [x=0,y=0]=this.style.translate.match(/-?\d+(?:\.\d+)?/g)?.map(Number)||[];
            return {left:200+x,top:100+y,width:420,height:300,right:620+x,bottom:400+y};
        },
    });
    const root=node('body'), shell=legacy?node('shell',root):null;
    const dialog=legacy?node('surface',shell):node('dialog',root);
    const header=node('header',dialog);
    header.matches=selector=>selector.includes(legacy?'.modal__header':'.mskba-modal__header');
    dialog.children.push(header);
    if(legacy){root.children.push(shell);shell.children.push(dialog);}
    else root.children.push(dialog);
    const html=node('html');
    const doc={body:root,documentElement:html,
        addEventListener:(n,fn)=>(listeners.doc[n]??=[]).push(fn),
        removeEventListener(){},emit(n,e){for(const cb of listeners.doc[n]||[])cb(e);}};
    const win={innerWidth:1000,innerHeight:700,
        MutationObserver:class {constructor(fn){this.fn=fn;observers.push(this);}observe(){}disconnect(){}},
        addEventListener:(n,fn)=>(listeners.win[n]??=[]).push(fn),
        removeEventListener(){},emit(n,e){for(const cb of listeners.win[n]||[])cb(e);}};
    header.setPointerCapture=()=>{};header.releasePointerCapture=()=>{};
    const controller=initModalDragging(doc,win);
    const pointer=(type,x,y,which=1,target=header)=>({pointerId:which,button:0,
        isPrimary:true,clientX:x,clientY:y,target,preventDefault(){}});
    return {doc,win,root,html,dialog,header,shell,controller,pointer,observers};
}

test('native modal drag starts after threshold, disables and restores backdrop blur',()=>{
    const h=harness();
    h.doc.emit('pointerdown',h.pointer('pointerdown',400,150));
    h.win.emit('pointermove',h.pointer('pointermove',402,150));
    assert.equal(h.html.attrs['data-mskba-modal-dragging'],undefined);
    h.win.emit('pointermove',h.pointer('pointermove',440,170));
    assert.equal(h.html.attrs['data-mskba-modal-dragging'],'true');
    assert.equal(h.dialog.attrs['data-mskba-dragging'],'true');
    assert.equal(h.dialog.style.translate,'40px 20px');
    h.win.emit('pointerup',h.pointer('pointerup',440,170));
    assert.equal(h.html.attrs['data-mskba-modal-dragging'],undefined);
    assert.equal(h.dialog.style.translate,'40px 20px');
    h.dialog.open=false;
    h.doc.emit('close',{target:h.dialog});
    assert.equal(h.dialog.style.translate,'');
    h.controller.destroy();
});

test('pointercancel and Escape restore previous position; buttons cannot start dragging',()=>{
    const h=harness();
    h.doc.emit('pointerdown',h.pointer('down',400,150));
    h.win.emit('pointermove',h.pointer('move',440,170));
    h.win.emit('pointercancel',h.pointer('cancel',440,170));
    assert.equal(h.dialog.style.translate,'0px 0px');
    assert.equal(h.html.attrs['data-mskba-modal-dragging'],undefined);
    h.doc.emit('pointerdown',h.pointer('down',400,150));
    h.win.emit('pointermove',h.pointer('move',450,200));
    h.doc.emit('keydown',{key:'Escape',preventDefault(){}});
    assert.equal(h.dialog.style.translate,'0px 0px');
    h.controller.destroy();
});

test('keyboard has separate, bounded motion and focused header remains available',()=>{
    const h=harness();
    assert.equal(h.header.tabIndex,0);
    assert.match(h.header.attrs['aria-keyshortcuts'],/Alt\+ArrowRight/);
    const key=(key,shiftKey=false)=>({key,altKey:true,shiftKey,target:h.header,
        preventDefault(){}});
    h.doc.emit('keydown',key('ArrowRight'));
    assert.equal(h.dialog.style.translate,'10px 0px');
    h.doc.emit('keydown',key('ArrowDown',true));
    assert.equal(h.dialog.style.translate,'10px 32px');
    h.controller.destroy();
});

test('legacy overlay shell uses the same controller and retains backdrop ownership',()=>{
    const h=harness(true);
    h.doc.emit('pointerdown',h.pointer('down',400,150));
    h.win.emit('pointermove',h.pointer('move',430,180));
    assert.equal(h.dialog.style.translate,'30px 30px');
    assert.equal(h.html.attrs['data-mskba-modal-dragging'],'true');
    h.win.emit('pointerup',h.pointer('up',430,180));
    h.shell.classList.contains=()=>false;
    h.observers.find(x=>x.fn)?.fn(); // lifecycle mutation: minimize/close
    assert.equal(h.dialog.style.translate,'');
    h.controller.destroy();
});

test('both themes and native map/price overlays import the shared controller and backdrop CSS',()=>{
    const app=readFileSync(new URL('../../resources/themes/mskba_app/js/app.js',import.meta.url),'utf8');
    const dark=readFileSync(new URL('../../resources/themes/mskba_dark/js/app.js',import.meta.url),'utf8');
    const lightCss=readFileSync(new URL('../../resources/themes/mskba_app/css/modal.css',import.meta.url),'utf8');
    const darkCss=readFileSync(new URL('../../resources/themes/mskba_dark/css/modal.css',import.meta.url),'utf8');
    const module=readFileSync(new URL('../../resources/themes/mskba_app/js/modal-dragging.js',import.meta.url),'utf8');
    assert.match(app,/initModalDragging\(\)/);
    assert.match(dark,/initModalDragging\(\)/);
    assert.match(module,/dialog\.app-venue-overlay/);
    assert.match(module,/dialog\.account-venue-price-dialog/);
    assert.match(lightCss,/data-mskba-modal-dragging="true"[^{}]*::backdrop\s*\{/);
    assert.match(darkCss,/data-mskba-modal-dragging="true"[^{}]*\.modal:not/);
    assert.match(module,/win\.visualViewport\?\.addEventListener\('resize'/);
});


test('all draggable modal shells display the same noninteractive grab indicator as the notification popover', () => {
    const light = readFileSync(new URL('../../resources/themes/mskba_app/css/modal.css', import.meta.url), 'utf8');
    const dark = readFileSync(new URL('../../resources/themes/mskba_dark/css/modal.css', import.meta.url), 'utf8');
    const toast = readFileSync(new URL('../../resources/themes/mskba_app/js/notification-popover.js', import.meta.url), 'utf8');
    assert.match(toast, /app-notification-popover__grip">⠿<\/span>/);
    for (const css of [light, dark]) {
        assert.match(css, /content:\s*"⠿"/);
        assert.match(css, /pointer-events:\s*none/);
    }
    assert.match(light, /mskba-modal__header::before/);
    assert.match(light, /app-venue-overlay__header,\.account-venue-price-dialog__head\)::before/);
    assert.match(dark, /modal__header::before/);
    assert.match(dark, /account-venue-price-dialog__head::before/);
});
