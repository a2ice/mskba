<style>
.studio-event-page{--app-sticky-offset:0px;--venue-sidebar-sticky-top:calc(var(--studio-nav-height,58px) + 15px)}
.studio-event-page .app-venue-page__sections>.app-venue-block{scroll-margin-top:calc(var(--studio-nav-height,58px) + 15px)}
.studio-event-page .app-venue-intro{display:flex;flex-direction:column;gap:16px}
.studio-event-page .app-venue-intro__actions{margin-top:auto}
.studio-event-time{display:grid;gap:4px;font-size:15px}
.studio-event-time .muted{font-size:13px}
.studio-event-page .app-venue-block{min-width:0;align-items:stretch}
.studio-event-page .venue-play-dialog__wall{border-top:0;padding-top:0}
.studio-event-page .venue-play-dialog__messages{max-height:420px}
.studio-event-page .venue-play-dialog__roster h4{font-size:20px;margin:0}
.studio-event-page .studio-event-discussion .app-venue-block__header{margin-bottom:0}
.studio-event-page .studio-event-aside .app-venue-activity__title{font-size:14px}
.studio-event-page .venue-play-dialog__participant-star{font-size:18px}
.studio-event-page .studio-event-aside{display:grid;gap:12px;min-width:0;align-content:start}
.studio-event-aside p{margin:0}
.studio-event-link{color:var(--accent);text-decoration:none}
.studio-event-link:hover{text-decoration:underline}
.studio-event-games{display:grid;gap:10px}
.studio-event-where .button,.studio-event-where .app-venue-intro__address{align-self:flex-start}
.studio-event-where p{margin:0}
.studio-event-venue-name{font-weight:650}
.studio-event-audience{min-width:0}
.studio-event-audience .app-venue-interest-summary__avatars{isolation:isolate}
.studio-event-audience .app-venue-interest-summary__avatar{position:relative;overflow:visible;border-color:var(--surface);background:var(--surface-raised)}
.studio-event-audience .app-venue-interest-summary__avatar+.app-venue-interest-summary__avatar{margin-left:-12px}
.studio-event-audience .app-venue-interest-summary__avatar img{border-radius:50%}
.studio-event-audience .app-venue-interest-summary__avatar::after{content:"";position:absolute;right:0;bottom:0;width:10px;height:10px;border:2px solid var(--surface);border-radius:50%;background:var(--success)}
.studio-event-audience .app-venue-interest-summary__avatar[data-status="tentative"]::after{background:var(--warning)}
.studio-event-audience .app-venue-interest-summary__avatar[data-status="left"]::after{background:var(--danger)}
.studio-event-audience-count{display:inline-flex;align-items:center;min-height:44px;color:var(--accent);font-weight:650;text-decoration:none;text-underline-offset:3px}
.studio-event-audience-count:hover{text-decoration:underline}
.studio-gallery-fallback{position:absolute;inset:0;display:grid;place-items:center;background:var(--surface-raised);color:var(--muted)}
.studio-gallery-fallback svg{width:64px;height:64px;fill:none;stroke:currentColor;opacity:.5}
.studio-rental-active{color:var(--success)}
.studio-event-dialog{width:min(480px,calc(100vw - 24px));max-height:90dvh}
.studio-event-dialog > .mskba-modal__header{display:grid;grid-template-columns:16px minmax(0,1fr) 44px;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid var(--line)}
.studio-event-dialog > .mskba-modal__header h2{margin:0;font-size:clamp(19px,2vw,25px);line-height:1.3;min-width:0;overflow-wrap:anywhere}
.studio-event-dialog > .mskba-modal__header .icon-button{width:44px;height:44px;justify-self:end}
.studio-event-dialog .mskba-modal__body{display:grid;gap:16px;align-content:start;padding:20px 22px}
html:has(.studio-event-dialog[open]){overflow:hidden}
@media(max-width:540px){.studio-event-dialog > .mskba-modal__header,.studio-event-dialog .mskba-modal__body{padding:16px}.studio-event-dialog > .mskba-modal__header{gap:8px}}
.studio-event-dialog .mskba-modal__body p{margin:0}
.studio-event-choices{display:flex;justify-content:space-around;align-items:center;gap:12px;flex-wrap:wrap;padding-block:4px}
.studio-participate-copy{color:var(--text);line-height:1.5}
.studio-event-choices .button{min-width:125px;flex:0 1 auto}
.studio-share-choices{display:grid;gap:12px}
.studio-share-choices>.button{white-space:normal;height:auto;min-height:44px}
.studio-event-facts{display:grid;gap:14px;margin:0}
.studio-event-facts>div{display:grid;grid-template-columns:minmax(90px,.8fr) minmax(0,1.4fr);gap:12px}
.studio-event-facts dt{color:var(--muted)}
.studio-event-facts dd{margin:0;overflow-wrap:anywhere}
.studio-event-page [hidden],.studio-event-dialog [hidden]{display:none!important}
@media(max-width:900px){.studio-event-page .app-venue-page__aside{position:static;order:1}.studio-event-page .app-venue-page__sections{order:0}}
 .studio-feedback-fields{display:grid;gap:16px}
.studio-feedback-fields label{display:flex;align-items:flex-start;gap:10px;min-width:0;line-height:1.5}
.studio-feedback-fields input{flex:none;width:19px;height:19px;margin:3px 0 0;accent-color:var(--accent)}
.studio-feedback-fields label span{display:grid;gap:4px}
.studio-feedback-fields small{color:var(--muted);font-size:13px}
.studio-feedback-dialog .mskba-modal__footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid var(--line)}
.studio-event-completed,.studio-event-like-tally{display:inline-flex;align-items:center;gap:6px}
.studio-event-like-tally{gap:4px;font-variant-numeric:tabular-nums}
.studio-event-like-tally svg{width:14px;height:14px;fill:var(--accent);stroke:var(--accent);stroke-width:1.5;flex:none}
</style>
