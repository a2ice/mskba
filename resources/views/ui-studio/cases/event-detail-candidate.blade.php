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
.studio-event-games{display:grid;gap:10px;border-top:1px solid var(--line);padding-top:14px}
.studio-rental-active{color:var(--success)}
.studio-event-dialog{width:min(480px,calc(100vw - 24px));max-height:90dvh}
.studio-event-dialog .mskba-modal__body{display:grid;gap:16px}
.studio-event-dialog .mskba-modal__body p{margin:0}
.studio-event-choices{display:flex;justify-content:space-around;align-items:center;gap:12px;flex-wrap:wrap;padding-block:10px}
.studio-event-choices .button{min-width:125px;flex:0 1 auto}
.studio-share-choices{display:grid;gap:12px}
.studio-share-choices>.button{white-space:normal;height:auto;min-height:44px}
.studio-event-facts{display:grid;gap:14px;margin:0}
.studio-event-facts>div{display:grid;grid-template-columns:minmax(90px,.8fr) minmax(0,1.4fr);gap:12px}
.studio-event-facts dt{color:var(--muted)}
.studio-event-facts dd{margin:0;overflow-wrap:anywhere}
.studio-event-page [hidden],.studio-event-dialog [hidden]{display:none!important}
@media(max-width:900px){.studio-event-page .app-venue-page__aside{position:static;order:0}.studio-event-page .app-venue-page__sections{order:1}}
</style>
