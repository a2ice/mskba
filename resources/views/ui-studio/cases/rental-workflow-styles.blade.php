<style>
.rental-studio{padding-block:16px;display:grid;gap:12px;min-width:0}
.rental-studio .rs-controls{display:grid;gap:10px;padding:12px}
.rental-studio .rs-controls h1{font-size:18px;margin:0}
.rental-studio .rs-control-row,.rental-studio .rs-role-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.rental-studio .rs-control-row label{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.rental-studio .rs-controls .button{font-size:13px}
.rental-studio .rs-muted{color:var(--muted);font-size:12px;margin:0}
.rental-studio .rs-workspace{grid-template-columns:minmax(230px,290px) minmax(0,1fr);align-items:start}
.rental-studio .rs-rail{display:block;min-width:0;max-height:none}
.rental-studio .rs-rail .app-messages__rail-list{display:grid;gap:6px}
.rental-studio .rs-group{display:grid;gap:6px;border:1px solid var(--line);border-radius:var(--radius-card,12px);padding:8px}
.rental-studio .rs-group summary{cursor:pointer;min-width:0;overflow-wrap:anywhere}
.rental-studio .rs-rail-item{width:100%;text-align:left;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px;padding:8px;background:var(--surface);color:var(--text);border:1px solid var(--line);border-radius:8px;font:inherit;cursor:pointer}
.rental-studio .rs-rail-item[aria-current=true]{border-color:var(--accent);background:var(--surface-raised)}
.rental-studio .rs-ellipsis{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;min-width:0}
.rental-studio .rs-thread{max-width:none;min-width:0}
.rental-studio .rs-log{height:clamp(300px,52vh,620px);min-height:0;overflow:auto}
.rental-studio .rs-thread .app-messages__compose{max-width:none}
.rental-studio .rs-card{align-self:stretch;width:auto;max-width:100%;min-width:0}
.rental-studio .rs-card p{margin:0;overflow-wrap:anywhere}
.rental-studio .rs-card .button{align-self:flex-start;max-width:100%;white-space:normal}
.rental-studio .rs-offer{display:grid;gap:6px;border:1px solid var(--line);border-radius:8px;padding:10px}
.rental-studio .rs-modal{width:min(920px,calc(100% - 24px));max-height:90dvh}
.rental-studio .rs-modal .mskba-modal__body{display:grid;gap:12px;min-height:0;overflow:auto;padding:12px}
.rental-studio .rs-modal .mskba-modal__footer{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.rental-studio .rs-modal textarea{width:100%;min-height:90px}
.rental-studio .rs-grid-scroll{overflow:auto;max-height:min(48vh,480px);border:1px solid var(--line);border-radius:8px}
.rental-studio .rs-grid{border-collapse:separate;border-spacing:1px;min-width:650px;width:100%;table-layout:fixed;font-size:12px}
.rental-studio .rs-grid th{position:sticky;top:0;background:var(--surface-raised);z-index:2;padding:6px;color:var(--text)}
.rental-studio .rs-grid th:first-child{left:0;z-index:3;width:58px}
.rental-studio .rs-grid tbody th{top:auto;z-index:1}
.rental-studio .rs-grid .rs-weekend{color:var(--danger)}
.rental-studio .rs-slot{width:100%;height:28px;border:1px solid var(--line);background:var(--surface);color:var(--text);cursor:pointer;padding:0;font:inherit}
.rental-studio .rs-slot[data-kind=unknown]{background:color-mix(in srgb,var(--muted) 24%,var(--surface))}
.rental-studio .rs-slot[data-kind=closed]{background:repeating-linear-gradient(135deg,var(--field),var(--field) 5px,var(--line) 5px,var(--line) 6px);cursor:not-allowed}
.rental-studio .rs-slot[data-kind=busy]{background:color-mix(in srgb,var(--danger) 54%,var(--surface));border-inline-color:var(--text)}
.rental-studio .rs-slot[data-kind=held]{background:color-mix(in srgb,var(--warning) 50%,var(--surface))}
.rental-studio .rs-slot[data-requested=true]{outline:2px solid var(--accent);outline-offset:-2px}
.rental-studio .rs-slot[data-selected=requested]{background:color-mix(in srgb,var(--success) 55%,var(--surface))}
.rental-studio .rs-slot[data-selected=alternative]{background:color-mix(in srgb,var(--accent) 60%,var(--surface))}
.rental-studio .rs-slot:focus-visible{outline:2px solid var(--text);outline-offset:-2px}
.rental-studio .rs-status{font-weight:600;color:var(--accent)}
@media(max-width:850px){.rental-studio .rs-workspace{display:grid;grid-template-columns:minmax(0,1fr)}.rental-studio .rs-rail{display:block}.rental-studio .rs-log{height:360px}.rental-studio .rs-controls{padding:10px}.rental-studio .rs-modal{max-width:calc(100% - 16px)}}
</style>
