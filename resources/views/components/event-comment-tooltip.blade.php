@if(filled($commentText))
    <details class="event-comment-tooltip" style="display:inline-flex;align-items:center" data-event-comment-tooltip>
        <summary aria-label="{{ $commentLabel }}" style="cursor:pointer;list-style:none;display:grid;place-items:center;min-width:32px;height:32px;font-size:14px;line-height:1">
            <span aria-hidden="true" style="border:1px solid currentColor;border-radius:50%;width:20px;height:20px;display:grid;place-items:center">?</span>
        </summary>
        <div role="note" style="position:absolute;z-index:10;left:0;top:100%;width:min(280px,100%);box-sizing:border-box;padding:12px;background:var(--surface,#fff);color:var(--text,#181c23);border:1px solid var(--line,#ccc);border-radius:12px;box-shadow:0 8px 28px #0002;font-size:14px;font-weight:normal;white-space:pre-line;overflow-wrap:anywhere">{{ $commentText }}</div>
    </details>
    <script>
    (()=>{
        const details=document.currentScript.previousElementSibling;
        document.addEventListener('click',event=>{if(details.open&&!details.contains(event.target))details.open=false;});
        details.addEventListener('keydown',event=>{if(event.key==='Escape'&&details.open){event.preventDefault();details.open=false;details.querySelector('summary').focus();}});
    })();
    </script>
@endif
