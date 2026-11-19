// Venue rentals live next to the schedule; do not mix them with
// the "Хочу здесь играть" event-only funnel or with favorites.
(() => {
    const choice=document.querySelector('[data-venue-choice-dialog]');
    const dayDialog=document.querySelector('[data-venue-day-dialog]');
    if(!choice || !dayDialog)return;
    const select=choice.querySelector('[data-venue-choice-court]');
    const error=choice.querySelector('[data-venue-choice-error]');
    let desiredDate=null;
    const open=(date=null)=>{
        desiredDate=date;error.hidden=true;
        if(!choice.open)choice.showModal();
    };
    document.addEventListener('click',event=>{
        const button=event.target.closest?.('[data-venue-rental-open]');
        if(!button)return;
        event.preventDefault();open();
    });
    window.addEventListener('mskba:venue-court-selection-required',event=>{
        open(event.detail?.date||null);
    });
    choice.querySelector('[data-venue-choice-date]')?.addEventListener('click',()=>{
        const courtId=Number(select?.value);
        if(!Number.isSafeInteger(courtId)||courtId<1){
            error.hidden=false;select?.focus();return;
        }
        choice.close();
        dayDialog.dispatchEvent(new CustomEvent('mskba:choose-court',{
            detail:{courtId,date:desiredDate},
        }));
    });
    select?.addEventListener('change',()=>{error.hidden=true;});
    choice.querySelectorAll('[data-venue-choice-close]').forEach(button=>
        button.addEventListener('click',()=>choice.close()));
    let downOutside=false;
    choice.addEventListener('pointerdown',event=>{downOutside=event.target===choice;});
    choice.addEventListener('pointerup',event=>{if(downOutside&&event.target===choice)choice.close();downOutside=false;});
})();
