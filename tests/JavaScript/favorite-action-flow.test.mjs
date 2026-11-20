import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const src = readFileSync(new URL('../../resources/themes/mskba_app/js/favorite-action.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/m, '').replace('export async function addFavorite', 'async function addFavorite');
const tick = () => new Promise(resolve => setImmediate(resolve));
function harness(initialAuth = false, stored = new Map()) {
    const target = () => {
        const callbacks = new Map();
        return { addEventListener: (name, callback) => callbacks.set(name, callback),
            fire(name, event = {}) { return callbacks.get(name)?.(event); } };
    };
    const dialog = { ...target(), open: false, showModal() { this.open = true; },
        close() { this.open = false; this.fire('close'); } };
    const nodes = Object.fromEntries(['text', 'submit', 'close'].map(name => [name, {...target(), textContent:''}]));
    dialog.querySelector = selector => nodes[selector.match(/data-favorite-action-(\w+)/)[1]];
    const meta = { content: initialAuth ? '1' : '0', setAttribute(k,v) { this[k]=v; } };
    const events = [], requests = [];
    const window = {...target(), dispatchEvent(e) {events.push(e); this.fire(e.type,e);}};
    const document = {readyState:'complete', querySelector(selector) {
        if (selector==='[data-favorite-action-dialog]') return dialog;
        if (selector.includes('mskba-authenticated')) return meta;
        if (selector.includes('csrf-token')) return { content:'csrf' };
        return null;
    }, querySelectorAll:()=>[], getElementById:()=>({})};
    const location = {pathname:'/venues/13',search:'',hash:'',origin:'https://mskba.ru',assign:()=>assert.fail('unexpected redirect')};
    const sessionStorage = { setItem:(k,v)=>stored.set(k,v),getItem:k=>stored.get(k)??null,removeItem:k=>stored.delete(k) };
    const fetch = async (url, options) => { requests.push(JSON.parse(options.body)); return {ok:true,status:200,json:async()=>({state:'active',count:1,favorites_count:1})}; };
    const CustomEvent = class {constructor(type,opts){this.type=type;this.detail=opts?.detail;}};
    const api = runInNewContext(src+'\n({addFavorite})',{window,document,location,sessionStorage,fetch,CustomEvent,URL});
    return {api,dialog,nodes,window,events,requests,stored};
}
test('guest add does not POST; login click then successful authentication adds once', async () => {
    const h=harness();
    await h.api.addFavorite('venue',13);
    assert.equal(h.requests.length,0);
    assert.equal(h.nodes.text.textContent,'Для того чтобы добавить площадку в избранное, необходимо авторизоваться');
    assert.equal(h.nodes.submit.textContent,'Войти');
    assert.equal(h.stored.size,0);
    h.nodes.submit.fire('click');
    assert.equal(h.stored.size,1);
    assert.equal(h.events.at(-1).type,'mskba:auth:open');
    h.window.fire('mskba:auth:success');
    h.window.fire('mskba:auth:success');
    await tick();
    assert.deepEqual(h.requests,[{type:'venue',id:13,action:'add'}]);
    assert.equal(h.stored.size,0);
    assert.equal(h.nodes.text.textContent,'Площадка добавлена в избранное.');
    assert.equal(h.nodes.submit.textContent,'Понятно');
});
test('cancelling prompt or auth cannot add a favorite', async () => {
    const h=harness();
    await h.api.addFavorite('product',3);
    h.nodes.close.fire('click');
    assert.equal(h.stored.size,0);
    await h.api.addFavorite('product',3);
    h.nodes.submit.fire('click');
    h.window.fire('mskba:auth:cancel');
    h.window.fire('mskba:auth:success');
    await tick();
    assert.equal(h.requests.length,0);
});
test('return from redirect continues the one-shot pending entity', async () => {
    const guest=harness();
    await guest.api.addFavorite('team',5);
    guest.nodes.submit.fire('click');
    const logged=harness(true,guest.stored);
    await tick();
    assert.deepEqual(logged.requests,[{type:'team',id:5,action:'add'}]);
    assert.equal(logged.stored.size,0);
    assert.equal(logged.nodes.text.textContent,'Команда добавлена в избранное.');
});
