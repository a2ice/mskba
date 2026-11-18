const page=document.querySelector('[data-play-opportunity-page]');
if(page){
    const buttons=[...page.querySelectorAll('[data-play-opportunity-tab]')];
    const panels=[...page.querySelectorAll('[data-play-opportunity-panel]')];
    const known=new Set(buttons.map(b=>b.dataset.playOpportunityTab));
    const select=(name,store=false)=>{
        const key=known.has(name)?name:'overview';
        buttons.forEach(b=>{
            const chosen=b.dataset.playOpportunityTab===key;
            b.classList.toggle('is-active',chosen);
            if(chosen)b.setAttribute('aria-current','page');
            else b.removeAttribute('aria-current');
        });
        panels.forEach(p=>p.hidden=p.dataset.playOpportunityPanel!==key);
        if(store)history.replaceState(null,'','#'+key);
    };
    const value=location.hash.slice(1);
    if(known.has(value))select(value);
    buttons.forEach(button=>button.addEventListener('click',()=>select(button.dataset.playOpportunityTab,true)));
    window.addEventListener('hashchange',()=>select(location.hash.slice(1)));
    page.querySelectorAll('[data-group-time-mode]').forEach(selectField=>{
        const parent=selectField.closest('form');
        const weekday=parent.querySelector('[data-group-time-weekday]');
        const date=parent.querySelector('[data-group-time-date]');
        const refresh=()=>{
            if(weekday)weekday.hidden=selectField.value!=='weekly';
            if(date)date.hidden=selectField.value!=='date';
        };
        selectField.addEventListener('change',refresh);
        refresh();
    });
}
