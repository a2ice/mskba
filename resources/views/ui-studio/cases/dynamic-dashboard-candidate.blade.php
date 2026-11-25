{{-- Candidate-local styles, revision 1. Existing panel/grid/actions remain the primitives. --}}
<style>
.dashboard-dynamic{display:flex;flex-direction:column;gap:12px;width:100%;min-width:0}
.dashboard-dynamic__count{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.dashboard-dynamic__count strong{font-size:26px;line-height:1;font-variant-numeric:tabular-nums;color:var(--text)}
.dashboard-dynamic__count span,.dashboard-dynamic__split span{font-size:13px;color:var(--muted)}
.dashboard-dynamic__list{list-style:none;margin:0;padding:0;width:100%;min-width:0}
.dashboard-dynamic__item{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--line);min-width:0}
.dashboard-dynamic__item-text{flex:1;min-width:0;overflow-wrap:anywhere;display:grid;gap:3px}
.dashboard-dynamic__item-text p{font-size:13px;line-height:1.45}
.dashboard-dynamic__link{display:block;text-align:left;font:inherit;font-weight:650;color:var(--text);text-decoration:none;padding:0;border:0;background:transparent;cursor:pointer;overflow-wrap:anywhere}
.dashboard-dynamic__link:hover{text-decoration:underline;text-underline-offset:3px;color:var(--accent)}
.dashboard-dynamic__link:focus-visible{outline:2px solid var(--accent);outline-offset:3px;border-radius:3px}
.dashboard-dynamic__balance{display:grid;gap:12px}
.dashboard-dynamic__balance>strong{font-size:clamp(26px,4vw,34px);line-height:1.15;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.dashboard-dynamic__split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.dashboard-dynamic__split>div{display:grid;gap:4px;min-width:0;overflow-wrap:anywhere}
.dashboard-dynamic__delta{font-size:14px;font-variant-numeric:tabular-nums;max-width:45%;text-align:right;overflow-wrap:anywhere}
.dashboard-dynamic__delta.is-positive{color:var(--success)}
.dashboard-dynamic__skeleton{display:grid;gap:10px;width:100%}
.dashboard-dynamic__skeleton span{display:block;height:18px;background:var(--surface-raised);border-radius:var(--radius-control);width:85%}
.dashboard-dynamic__skeleton span:nth-child(2){width:65%}
.dashboard-dynamic__skeleton span:nth-child(3){width:75%}
.dashboard-dynamic .app-dashboard-widget__actions{margin-top:0}
[data-studio-dashboard]{padding-block:24px;max-width:1000px}
[data-studio-dashboard] h1{margin:0 0 18px}
@media(max-width:390px){.dashboard-dynamic__item{gap:8px}.dashboard-dynamic__split{gap:8px}}
</style>
