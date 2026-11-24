import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const source=readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorite-main.js',import.meta.url),'utf8')
    .replace(/^import .*;\n/m,'');
const tick=()=>new Promise(resolve=>setImmediate(resolve));

function harness({authenticated=false,status='none',count=3,fetchImpl=null,eventUrl=null}={}){
    const listeners=new Map(), calls=[];
    const hero={dataset:{favoriteCount:String(count)},textContent:'',disabled:false,
        setAttribute(key,value){this[key]=value;},addEventListener(_,handler){this.click=handler;}};
    function follow(){
        const dialog={open:false,close(){this.open=false;this.closed=(this.closed??0)+1;}};
        const message={textContent:''},label={textContent:''};
        const action={disabled:true,closest(){return dialog;},
            setAttribute(key,value){this[key]=value;},
            addEventListener(_,handler){this.click=()=>handler({currentTarget:action});}};
        const root={dataset:{state:'unknown'},querySelector(selector){return {
            '[data-venue-favorite-follow-message]':message,
            '[data-venue-favorite-follow-label]':label,
            '[data-venue-favorite-follow-action]':action
        }[selector]??null;}};
        return {root,message,label,action,dialog};
    }
    const audience=follow(),games=follow();
    if(eventUrl) audience.root.dataset.createEventUrl=eventUrl;
    const controls={
        '[data-venue-favorite-title]':{textContent:''},
        '[data-venue-favorite-description]':{textContent:''},
        '[data-venue-favorite-error]':{textContent:'',hidden:true},
        '[data-venue-favorite-submit]':{textContent:'',disabled:false,addEventListener(){}},
        '[data-venue-favorite-close]':{addEventListener(){}},
    };
    const modal={dataset:{venueId:'8',statusUrl:'/favorites/venue-statuses',toggleUrl:'/favorites/toggle'},
        querySelector(selector){return controls[selector];},
        addEventListener(){},open:false,showModal(){this.open=true;},close(){this.open=false;}};
    const authMeta={content:authenticated?'1':'0'};
    const document={
        querySelector(selector){if(selector==='[data-venue-favorite-dialog]')return modal;
            if(selector==='meta[name="mskba-authenticated"]')return authMeta;
            if(selector==='[data-venue-play-dialog]')return {dataset:{authenticated:authMeta.content}};
            return null;},
        querySelectorAll(selector){if(selector==='[data-venue-favorite-main]')return [hero];
            if(selector==='[data-venue-favorite-follow]')return [audience.root,games.root];return [];}
    };
    const window={location:{assign(url){calls.push({navigated:url});}},
        addEventListener(key,handler){if(!listeners.has(key))listeners.set(key,[]);
            listeners.get(key).push(handler);},
        dispatchEvent(event){for(const h of listeners.get(event.type)??[])h(event);}};
    const fetch=fetchImpl??(async()=>({ok:true,json:async()=>({data:{8:{state:status}},counts:{8:count}})}));
    class CustomEvent {constructor(type,options){this.type=type;this.detail=options.detail;}}
    const addFavorite=async(type,id)=>{calls.push({type,id,sourceOpen:audience.dialog.open||games.dialog.open});return false;};
    runInNewContext(source,{document,window,fetch,addFavorite,URL,location:{href:'https://mskba.ru/venues/8'},CustomEvent});
    const emit=(state,total)=>window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',
        {detail:{venueId:8,state,count:total}}));
    return {hero,audience,games,authMeta,calls,window,emit};
}

test('guest can save venue directly from interested players without opening games popup',async()=>{
    const h=harness();await tick();
    h.audience.dialog.open=true;
    assert.equal(h.audience.label.textContent,'Добавить в избранное');
    assert.equal(h.audience.action.disabled,false);
    await h.audience.action.click();
    assert.equal(h.audience.dialog.open,false);
    assert.deepEqual(h.calls,[{type:'venue',id:8,sourceOpen:false}]);
    // Only the currently opened native dialog is closed; the global favorite
    // module decides whether to open login or the success dialog.
    assert.equal(h.games.dialog.open,false);
});

test('the games dialog shares one saved state and never offers removal',async()=>{
    const h=harness({authenticated:true,status:'active',count:4});await tick();
    for(const item of [h.audience,h.games]){
        assert.equal(item.root.dataset.state,'active');
        assert.equal(item.label.textContent,'В избранном');
        assert.equal(item.action.disabled,true);
        assert.match(item.message.textContent,/Площадка в избранном/);
    }
    assert.equal(h.hero.textContent,'В избранном (4)');
    await h.games.action.click(); // blocked by state guard, no HTTP mutation
    assert.equal(h.calls.length,0);
});

test('venue changes synchronize both dialogs and hero, including remove elsewhere',async()=>{
    const h=harness({authenticated:true,status:'none',count:3});await tick();
    h.emit('active',4);
    for(const item of [h.audience,h.games]){
        assert.equal(item.root.dataset.state,'active');
        assert.equal(item.action.disabled,true);
    }
    assert.equal(h.hero.textContent,'В избранном (4)');
    h.emit('trashed',3);
    assert.equal(h.hero.textContent,'В избранное (3)');
    for(const item of [h.audience,h.games]){
        assert.equal(item.label.textContent,'Добавить в избранное');
        assert.equal(item.action.disabled,false);
    }
});

test('authenticated user sees loading instead of assuming not saved until status resolves',async()=>{
    let done;
    const pending=new Promise(resolve=>{done=resolve;});
    const h=harness({authenticated:true,fetchImpl:async()=>pending});
    assert.equal(h.games.root.dataset.state,'loading');
    assert.equal(h.games.action.disabled,true);
    done({ok:true,json:async()=>({data:{8:{state:'active'}},counts:{8:6}})});
    await tick();
    assert.equal(h.games.root.dataset.state,'active');
    assert.equal(h.hero.textContent,'В избранном (6)');
});

test('an old GET never overwrites an updated favorite state',async()=>{
    let done;
    const pending=new Promise(resolve=>{done=resolve;});
    const h=harness({authenticated:true,fetchImpl:async()=>pending});
    h.emit('active',5);
    done({ok:true,json:async()=>({data:{8:{state:'none'}},counts:{8:2}})});
    await tick();
    assert.equal(h.hero.textContent,'В избранном (5)');
    assert.equal(h.games.root.dataset.state,'active');
});

test('guest status lookup failure still presents login-capable save action',async()=>{
    const h=harness({fetchImpl:async()=>{throw new Error('status unavailable');}});
    await tick();
    assert.equal(h.audience.label.textContent,'Добавить в избранное');
    assert.equal(h.audience.action.disabled,false);
    await h.audience.action.click();
    assert.equal(h.calls.length,1);
});

test('both popups use shared favorite prompt; event creation remains an independent action',()=>{
    const venue=readFileSync(new URL('../../resources/themes/mskba_app/views/pages/venues/show.blade.php',import.meta.url),'utf8');
    const games=readFileSync(new URL('../../resources/views/components/venue-play-funnel.blade.php',import.meta.url),'utf8');
    const prompt=readFileSync(new URL('../../resources/views/components/venue-favorite-follow.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(venue,/Тоже хочу здесь играть/);
    assert.match(venue,/@include\('components\.venue-favorite-follow'/);
    assert.match(games,/@include\('components\.venue-favorite-follow'/);
    assert.match(games,/data-quick-event-open/);
    assert.match(games,/data-venue-availability-open/);
    assert.match(games,/\$upcomingVenueEvents->isEmpty\(\) \? \$secondaryButton : \$primaryButton/);
    assert.match(prompt,/data-venue-favorite-follow-action/);
    assert.match(prompt,/venue-favorite-follow__icon/);
});

test('saved empty-state CTA creates an event without removing a favorite or affecting the games popup',async()=>{
    const h=harness({authenticated:true,status:'active',count:4,eventUrl:'/events/wizard?venue_id=8'});
    await tick();
    assert.equal(h.audience.label.textContent,'Создать мероприятие');
    assert.equal(h.audience.action.disabled,false);
    assert.equal(h.games.label.textContent,'В избранном');
    assert.equal(h.games.action.disabled,true);
    await h.audience.action.click();
    assert.deepEqual(h.calls,[{navigated:'/events/wizard?venue_id=8'}]);
});

test('favorites status changes do not replace favorite buttons via play-interest refresh',()=>{
    const summary=readFileSync(new URL('../../resources/themes/mskba_app/js/venue-interest-summary.js',import.meta.url),'utf8');
    assert.doesNotMatch(summary,/addEventListener\('mskba:venue-favorite:updated'/);
    assert.match(summary,/addEventListener\('mskba:play-intent:saved'/);
});
