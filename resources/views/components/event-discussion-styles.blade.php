@once
<style>
.venue-play-dialog{
    width:min(690px,calc(100vw - 24px));max-width:calc(100vw - 24px);
    max-height:90dvh;padding:0;overflow:hidden;color:var(--text,#fff);
}
.venue-play-dialog[open]{display:flex;flex-direction:column}
.venue-play-dialog:not([open]){display:none}
.venue-play-dialog:not(.venue-play-dialog--app){
    border:1px solid var(--line,#59606a);border-radius:16px;
    background:var(--surface,#1b1f27);box-shadow:0 20px 85px #0008;
}
.venue-play-dialog:not(.venue-play-dialog--app)::backdrop{background:rgba(0,0,0,.64)}
.venue-play-dialog:not(.venue-play-dialog--app)[open]>.venue-play-dialog__header::before{
    content:"⠿";align-self:center;justify-self:center;
    color:var(--muted,#929aa4);font-size:1rem;line-height:1;pointer-events:none;
}
.venue-play-dialog__header{
    display:grid;grid-template-columns:16px minmax(0,1fr) 44px;
    align-items:center;gap:12px;padding:20px 22px;
    border-bottom:1px solid var(--line,#59606a);
    cursor:grab;touch-action:none;user-select:none;
}
.venue-play-dialog__header h2{margin:0;font-size:clamp(19px,2vw,25px);line-height:1.3;min-width:0}
.venue-play-dialog__header .venue-play-dialog__close{
    width:44px;height:44px;display:flex;align-items:center;justify-content:center;
    justify-self:end;border:1px solid var(--line,#59606a);
    border-radius:12px;background:var(--surface,#1b1f27);color:inherit;cursor:pointer;
}
.venue-play-dialog__close svg{
    width:20px;height:20px;fill:none;stroke:currentColor;
    stroke-width:2;stroke-linecap:round;
}
.venue-play-dialog__body{
    flex:1 1 auto;min-height:0;overflow-y:auto;padding:20px 22px;
    display:grid;gap:16px;align-content:start;
}
.venue-play-dialog__body[hidden],.venue-play-dialog__detail[hidden]{display:none}
.venue-play-dialog__events{display:grid;gap:10px}
.venue-play-dialog__event{
    display:grid;gap:6px;width:100%;text-align:left;padding:14px;
    border:1px solid var(--line,#5c6570);border-radius:12px;
    background:var(--field,#272b32);color:inherit;text-decoration:none;cursor:pointer;
}
.venue-play-dialog__event:hover,.venue-play-dialog__event:focus-visible{
    border-color:var(--accent,#ea7628);
}
.venue-play-dialog__event-meta{font-size:13px;color:var(--muted,#929aa4)}
.venue-play-dialog__event strong{line-height:1.35}
.venue-play-dialog__event small{color:var(--muted,#929aa4)}
.venue-play-dialog__empty{margin:0;padding:24px 14px;text-align:center;color:var(--muted,#929aa4)}
.venue-play-dialog__footer{
    display:flex;justify-content:flex-end;flex:0 0 auto;
    gap:12px;padding:16px 22px;border-top:1px solid var(--line,#59606a);
}
.venue-play-dialog__footer[data-venue-play-empty-footer]{justify-content:space-around;gap:12px;flex-wrap:wrap}
.venue-play-dialog__footer[hidden]{display:none}
.venue-play-dialog__footer :is(.button,.btn){min-height:44px}
.venue-play-dialog__detail{display:grid;gap:20px;min-width:0}
.venue-play-dialog__summary,.venue-play-dialog__roster{display:grid;gap:10px;min-width:0}
.venue-play-dialog__summary h3,.venue-play-dialog h4,.venue-play-dialog__summary p,.venue-play-dialog__roster p,.venue-play-dialog__wall p{margin:0}
.venue-play-dialog__summary h3{font-size:clamp(21px,2.4vw,26px);line-height:1.3;overflow-wrap:anywhere}
.venue-play-dialog__summary p{line-height:1.5;overflow-wrap:anywhere}
.venue-play-dialog__roster-surface{position:relative;min-width:0;min-height:30px;border-radius:10px}
.venue-play-dialog__participants{display:grid;gap:9px;max-height:200px;overflow-y:auto;overscroll-behavior:contain;list-style:none;margin:0;padding:2px 6px 2px 0}
.venue-play-dialog__participants:empty{display:none}
.venue-play-dialog__participant{display:flex;align-items:center;gap:10px;min-width:0;min-height:30px;font-size:14px}
.venue-play-dialog__participant-name{min-width:0;overflow-wrap:anywhere}
.venue-play-dialog__participant-status{display:inline-block;flex:0 0 10px;width:10px;height:10px;border-radius:50%;background:#d97706}
.venue-play-dialog__participant-status.is-confirmed{background:#16a34a}
.venue-play-dialog__participant-status.is-declined{background:#dc2626}
.venue-play-dialog__participant-star{flex:none;color:#eab308;width:17px;height:17px;display:grid;place-items:center}
.venue-play-dialog__participant-star svg{width:100%;height:100%;fill:currentColor;stroke:currentColor}
.venue-play-dialog__avatar{width:30px;height:30px;flex:0 0 30px;display:grid;place-items:center;overflow:hidden;border-radius:50%;background:var(--surface-raised,var(--field,#444))}
.venue-play-dialog__avatar img{width:100%;height:100%;object-fit:cover}
.venue-play-dialog__avatar svg{width:18px;height:18px;stroke:currentColor;fill:none}
.venue-play-dialog__roster-surface .image-upload-loading{min-height:30px;padding:0;background:rgba(8,10,8,.35);backdrop-filter:blur(2px)}
.venue-play-dialog__roster-surface .image-upload-loading__spinner{width:24px;height:24px;border-right-color:var(--accent,#ea7628)}
.venue-play-dialog [hidden], [data-venue-play-page] [hidden]{display:none!important}
.venue-play-dialog__footer[data-venue-play-detail-footer]{justify-content:space-between;align-items:center}
.venue-play-dialog__footer[data-venue-play-detail-footer] :is(.button,.btn){width:auto;min-width:0;white-space:normal;text-align:center}
.venue-play-dialog__roster>button{justify-self:start}
.venue-play-dialog>[data-action-toast-modal]{position:absolute;inset:auto 12px 12px auto;z-index:40;width:min(320px,calc(100% - 24px));max-height:50%;overflow:auto}
.venue-play-dialog>[data-action-toast-modal] .app-action-toast{opacity:1;overflow-wrap:anywhere}
.notification-toast-region .app-action-toast--error{border-color:var(--danger,#ff8a8a)}
.venue-play-dialog__rsvp,.venue-play-dialog__message-footer{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.venue-play-dialog__rsvp .is-selected{outline:2px solid var(--accent,#ec792e);outline-offset:2px}
.venue-play-dialog__rsvp button{min-height:44px}
.venue-play-dialog__wall{border-top:1px solid var(--line,#59606a);padding-top:16px}
.venue-play-dialog__feedback{padding:10px;border-radius:8px;background:var(--field,#333)}
.venue-play-dialog__feedback.is-error{color:#f9a3a3}
.venue-play-dialog__wall{display:grid;gap:10px}
.venue-play-dialog__messages{display:grid;gap:10px;max-height:250px;overflow-y:auto;overflow-anchor:none;overscroll-behavior:contain;list-style:none;padding:0;margin:0}
.venue-play-dialog__messages li{padding:10px 12px;border-radius:8px;background:var(--field,#30343a);overflow-wrap:anywhere}
.venue-play-dialog__messages li:has(.venue-play-dialog__edit){position:relative;padding-right:48px}
.venue-play-dialog__edit,.venue-play-dialog__edit-cancel{display:inline-grid;place-items:center;min-width:36px;min-height:36px;border:0;background:transparent;color:inherit;cursor:pointer;border-radius:5px}
.venue-play-dialog__edit{position:absolute;right:4px;top:2px}
.venue-play-dialog__edit svg,.venue-play-dialog__edit-cancel svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.venue-play-dialog__edit:hover,.venue-play-dialog__edit:focus-visible,.venue-play-dialog__edit-cancel:hover,.venue-play-dialog__edit-cancel:focus-visible{color:var(--accent,#ea7628)}
.venue-play-dialog__edit-cancel{margin-left:auto}
.venue-play-dialog__wall form.is-editing{border-color:var(--accent,#ea7628)}
.venue-play-dialog__messages time{font-size:12px;opacity:.65;margin-left:9px}
.venue-play-dialog__messages li.is-system{opacity:.8}
.venue-play-dialog__messages li[data-rsvp-status]{opacity:1}
.venue-play-dialog__messages li[data-rsvp-status="confirmed"] p{color:var(--success,#7cdcab)}
.venue-play-dialog__messages li[data-rsvp-status="left"] p{color:var(--danger,#ff9398)}
.venue-play-dialog__messages li[data-rsvp-status="tentative"] p{color:var(--info,#8ccbff)}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="confirmed"] p{color:#166534}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="left"] p{color:#b91c1c}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="tentative"] p{color:#1e3a8a}
.venue-play-dialog__messages li.is-deleted{text-decoration:line-through;opacity:.6}
.venue-play-dialog__wall form{display:grid;gap:4px;padding:10px;border:1px solid var(--line,#cbd0d5);border-radius:5px;background:#fff;color:#18212b;min-width:0}
.venue-play-dialog__wall form:focus-within{border-color:var(--accent,#ea7628)}
.venue-play-dialog__wall textarea{width:100%;box-sizing:border-box;padding:0;min-height:56px;border:0;border-radius:0;outline:none;box-shadow:none;background:#fff;color:#18212b;resize:vertical}
.venue-play-dialog__wall textarea:focus{outline:none;box-shadow:none}
.venue-play-dialog__wall textarea::placeholder{color:#69717c}
.venue-play-dialog__message-footer{justify-content:space-between;flex-wrap:nowrap}
.venue-play-dialog__send{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;min-width:44px;padding:0}
.venue-play-dialog__send svg{width:22px;height:22px}
.venue-play-dialog__delete{border:0;border-radius:5px;background:#b91c1c;color:#fff;cursor:pointer}
.venue-play-dialog__delete:hover{background:#991b1b}
.venue-play-dialog__delete:disabled{opacity:.5;cursor:default}
.venue-play-delete-dialog{width:min(440px,calc(100vw - 24px))}
.venue-play-delete-dialog p{margin:0}
.venue-play-delete-dialog [data-venue-play-delete-error]{color:var(--danger,#dc2626)}
@media(max-width:540px){
    .venue-play-dialog__header,.venue-play-dialog__body{padding-inline:16px}
    .venue-play-dialog__footer{padding:16px}
    .venue-play-dialog__footer:not([data-venue-play-detail-footer]) :is(.button,.btn){width:100%}
}
</style>
@endonce