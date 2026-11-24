import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source=readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorite-main.js',import.meta.url),'utf8')
    .replace(/^import .*;\n/m,'');

const tick=()=>new Promise(resolve=>setImmediate(resolve));

test('venue favorite hero shows public active total for guests and refreshes on remote updates',async()=>{
    const listeners=new Map();
    const button={dataset:{favoriteCount:'3'},textContent:'В избранное (3)',disabled:false,
        addEventListener(event,handler){this.clickHandler=handler;},
        setAttribute(name,value){this[name]=value;}};
    const modal={dataset:{venueId:'8',statusUrl:'/favorites/venue-statuses',toggleUrl:'/favorites/toggle'},
        querySelector(selector){return controls[selector];},
        addEventListener(){},open:false,showModal(){this.open=true;},close(){this.open=false;}};
    const controls={
        '[data-venue-favorite-title]':{textContent:''},
        '[data-venue-favorite-description]':{textContent:''},
        '[data-venue-favorite-error]':{textContent:'',hidden:true},
        '[data-venue-favorite-submit]':{textContent:'',disabled:false,addEventListener(){}},
        '[data-venue-favorite-close]':{addEventListener(){}},
    };
    const window={addEventListener(name,callback){listeners.set(name,callback);},
        dispatchEvent(event){listeners.get(event.type)?.(event);}};
    const document={
        querySelector(selector){if(selector==='[data-venue-favorite-dialog]')return modal;return null;},
        querySelectorAll(selector){if(selector==='[data-venue-favorite-main]')return [button];return [];}
    };
    const fetch=async url=>{
        assert.match(String(url),/venue-statuses/);
        return {ok:true,json:async()=>({data:{8:{state:'none'}},counts:{8:3}})};
    };
    class CustomEvent {constructor(type,options){this.type=type;this.detail=options.detail;}}
    runInNewContext(source,{document,window,fetch,URL,location:{href:'https://mskba.ru/venues/8'},CustomEvent});
    await tick();
    assert.equal(button.textContent,'В избранное (3)');
    assert.equal(button['aria-pressed'],'false');
    window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',
        {detail:{venueId:8,state:'active',count:4}}));
    assert.equal(button.textContent,'В избранном (4)');
    assert.equal(button['aria-pressed'],'true');
    window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',
        {detail:{venueId:8,state:'trashed',count:3}}));
    assert.equal(button.textContent,'В избранное (3)');
    window.dispatchEvent(new CustomEvent('mskba:venue-favorite:updated',
        {detail:{venueId:999,state:'active',count:120}}));
    assert.equal(button.textContent,'В избранное (3)');
});

test('both venue themes render real active favorite count without conflating user header totals',()=>{
    for(const theme of ['mskba_app','mskba_dark']){
        const blade=readFileSync(new URL('../../resources/themes/'+theme+'/views/pages/venues/show.blade.php',import.meta.url),'utf8');
        assert.match(blade,/data-venue-favorite-main data-favorite-count="{{ \$favoritesCount }}"/);
        assert.match(blade,/В избранное \({{ \$favoritesCount }}\)/);
        assert.match(blade,/whereNull\('trashed_at'\)->count\(\)/);
    }
});
