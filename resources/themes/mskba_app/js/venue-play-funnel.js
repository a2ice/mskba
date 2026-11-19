// Task 337: the venue popup is only a list of upcoming events.
// Event cards lead to their canonical pages for RSVP/discussions.
// Event creation is handled by the shared quick-create wizard.
(() => {
    const dialog=document.querySelector('[data-venue-play-dialog]');
    if(!dialog || typeof dialog.showModal!=='function')return;

    document.addEventListener('click',event=>{
        const opener=event.target.closest?.('[data-venue-play-open]');
        if(!opener || opener.disabled)return;
        event.preventDefault();
        if(!dialog.open)dialog.showModal();
    });
    dialog.querySelector('[data-venue-play-close]')?.addEventListener('click',()=>dialog.close());

    // Dismiss only when the same pointer starts AND ends on the backdrop.
    // Dragging the title bar or selecting text must not dismiss the popup.
    let outsidePointer=null;
    dialog.addEventListener('pointerdown',event=>{
        outsidePointer=event.target===dialog?event.pointerId:null;
    });
    dialog.addEventListener('pointerup',event=>{
        if(outsidePointer===event.pointerId && event.target===dialog)dialog.close();
        outsidePointer=null;
    });
    dialog.addEventListener('pointercancel',()=>{outsidePointer=null;});
})();
